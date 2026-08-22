<?php

namespace App\Services;

use App\Contracts\AccessGrant;
use App\Enums\QuotaPeriodicity;
use App\Exceptions\PeriodiciteNonImplementee;
use App\Models\AccessGrantRecord;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\Exam;
use App\Models\QuestionConsumption;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * L'enveloppe de questions — lot 3B.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE SEUL ENDROIT QUI LIT UN RELIQUAT, EN CHOISIT UNE ET LA DÉBITE
 *
 * Trois chemins servent des items — diagnostic, examen blanc, entraînement. S'ils
 * comptaient chacun, on aurait trois façons subtilement différentes de compter,
 * et la troisième révélerait les écarts des deux premières un an plus tard.
 * C'est le raisonnement d'`AbonnementService::octroyerLesDroits`, appliqué au
 * débit.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LES TROIS RÈGLES D'AR-2, DANS CET ORDRE
 *
 *  1. **Aucun droit couvrant `questions.answer`** ⇒ la capacité est fermée.
 *     Ce n'est pas une enveloppe vide, c'est un mur (M-007), et les deux
 *     n'appellent pas la même conduite : souscrire, ou renouveler.
 *  2. **Un droit couvrant SANS quota** ⇒ consommation LIBRE. L'illimité gagne,
 *     et l'illimité est l'ABSENCE de profil, jamais un nombre (ADR-0027). La
 *     ligne de débit existe quand même, avec `access_grant_id` nul : c'est ce
 *     qui rend l'idempotence uniforme sur les deux chemins.
 *  3. **Sinon** ⇒ UNE SEULE enveloppe, celle qui EXPIRE LE PLUS TÔT ; « sans
 *     fin » compte pour l'infini, donc l'essai sans terme se débite en dernier.
 *     On consomme d'abord ce qui va se perdre — l'inverse ferait expirer des
 *     unités payées pendant qu'un reliquat gratuit dort.
 *
 * **Les reliquats non gouvernants ne bougent pas.** Ils ne sont ni débités, ni
 * remis à leur valeur initiale, ni vidés : à l'expiration du gouvernant, le
 * dormant reprend tel quel (S-01). Rien dans ce fichier ne les touche, et c'est
 * exactement ainsi qu'on l'obtient — un reliquat dérivé n'a pas besoin d'être
 * préservé, il l'est par construction.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA PORTÉE EST DONNÉE, LE VERROU EST PLUS LARGE
 *
 * La sélection interroge les droits COUVRANTS de l'épreuve, par la règle
 * d'ascendance (ADR-0031) : un droit `(audience, lycee)` couvre une épreuve de
 * ce public, un droit sans portée couvre tout.
 *
 * Le VERROU, lui, porte sur `(compte, capacité)` seulement, sans la portée que
 * l'ADR-0029 mentionne. C'est délibéré et c'est plus sûr : verrouiller plus
 * large ne permet aucun entrelacement que la clé fine interdirait, et la clé
 * fine aurait obligé à normaliser une portée pour un gain de débit dont personne
 * n'a besoin en version 1.0. Un compte a une poignée d'enveloppes, pas mille.
 */
final class EnveloppeDeQuestions
{
    /** La capacité comptée ici, et la seule. */
    public const CAPACITE = AccessGrant::QUESTIONS_ANSWER;

    /**
     * LA SEULE FENÊTRE QUE CE CODE SAIT COMPTER — arbitrage Q-07.
     *
     * « Cumulatif sur la durée du droit. Un renouvellement crée une nouvelle
     * enveloppe. Un droit sans terme ne se remet pas automatiquement à zéro. »
     * Une fenêtre glissante demanderait une règle de remise à zéro que personne
     * n'a écrite ; la servir avec ce compteur rendrait un chiffre faux.
     */
    public const FENETRE_IMPLEMENTEE = QuotaPeriodicity::CUMULATIVE_GRANT;

    public function __construct(private readonly AccessGrant $droits) {}

    /**
     * LE VERROU — à prendre en tête de la transaction qui compose et débite.
     *
     * Verrou transactionnel PostgreSQL : il se relâche au `COMMIT` comme au
     * `ROLLBACK`, sans qu'aucun chemin d'erreur n'ait à y penser. Une ligne
     * verrouillée aurait exigé qu'une ligne existe — or le compte sans
     * enveloppe n'en a aucune, et c'est précisément le cas où deux onglets
     * doivent quand même se sérialiser.
     */
    public function verrouiller(User $user): void
    {
        DB::statement(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            ['droit|'.$user->getKey().'|'.self::CAPACITE],
        );
    }

    /**
     * Les droits actifs COUVRANTS de cette épreuve, capacité comprise.
     *
     * La couverture passe par `AccessGrant::allows()` — la même lecture que le
     * mur payant. On ne réécrit pas la règle d'ascendance ici : deux
     * définitions de « couvrant » divergeraient, et c'est celle-ci qui aurait
     * tort.
     *
     * @return Collection<int, AccessGrantRecord>
     */
    public function couvrants(User $user, Exam $exam): Collection
    {
        if (! $this->droits->allows($user, self::CAPACITE, AccessGrantRecord::SCOPE_EXAM, $exam->uuid)) {
            return collect();
        }

        $ascendance = $this->ascendance($exam);

        return AccessGrantRecord::query()
            ->where('user_id', $user->getKey())
            ->where('capability', self::CAPACITE)
            ->active()
            ->get()
            ->filter(fn (AccessGrantRecord $droit): bool => $droit->scope_uuid === null
                || in_array($droit->scope_uuid, $ascendance, true))
            ->values();
    }

    /** La capacité est-elle ouverte à ce candidat, sur cette épreuve ? */
    public function ouverte(User $user, Exam $exam): bool
    {
        return $this->droits->allows($user, self::CAPACITE, AccessGrantRecord::SCOPE_EXAM, $exam->uuid);
    }

    /**
     * L'enveloppe qui gouverne la consommation, ou `null` si elle est libre.
     *
     * `null` signifie deux choses que l'appelant distingue par `ouverte()` :
     * capacité fermée, ou capacité ouverte sans enveloppe. Les mélanger ici
     * aurait fait rendre « illimité » à un compte qui n'a aucun droit.
     */
    public function gouvernante(User $user, Exam $exam): ?AccessGrantRecord
    {
        $couvrants = $this->couvrants($user, $exam);

        if ($couvrants->isEmpty()) {
            return null;
        }

        /* RÈGLE 2 — l'illimité gagne. Un seul droit sans quota suffit, et les
         * enveloppes ne s'additionnent jamais. */
        if ($couvrants->contains(fn (AccessGrantRecord $d): bool => $d->quota_value === null)) {
            return null;
        }

        /* RÈGLE 3 — celle qui expire le plus tôt ; « sans fin » vaut l'infini. */
        return $couvrants
            ->sortBy(fn (AccessGrantRecord $d): string => $d->ends_at?->toIso8601String() ?? '9999')
            ->first();
    }

    /**
     * Le reliquat d'une enveloppe — DÉRIVÉ, jamais lu dans une colonne.
     *
     * `quota_value − nombre de lignes de débit rattachées à ce droit`. Aucun
     * compteur ne se décrémente : un second dépositaire de la vérité finit par
     * diverger, et c'est alors le faux qui s'affiche.
     */
    public function reliquat(AccessGrantRecord $droit): int
    {
        $this->assertFenetreImplementee($droit);

        $consommees = QuestionConsumption::query()
            ->where('access_grant_id', $droit->getKey())
            ->count();

        return max(0, (int) $droit->quota_value - $consommees);
    }

    /**
     * Combien d'items peuvent encore être servis — `null` = sans limite.
     *
     * À lire SOUS LE VERROU : c'est ce nombre qui plafonne la composition, et
     * une lecture périmée composerait au-delà du reliquat.
     */
    public function plafond(User $user, Exam $exam): ?int
    {
        $gouvernante = $this->gouvernante($user, $exam);

        return $gouvernante === null ? null : $this->reliquat($gouvernante);
    }

    /**
     * Débite les items servis — une ligne par item, idempotente PAR LA BASE.
     *
     * `insertOrIgnore` plutôt qu'un `if` : le rejeu d'une même ouverture est
     * absorbé par `question_consumptions_unique_service`, et non par une
     * lecture préalable qui laisserait une fenêtre entre le contrôle et
     * l'écriture. La contrainte est la règle ; ceci n'en est que le canal.
     *
     * @param  Collection<int, AttemptItem>  $items
     * @return int le nombre de lignes réellement posées
     */
    public function debiter(User $user, Attempt $attempt, Collection $items, ?AccessGrantRecord $gouvernante): int
    {
        if ($items->isEmpty()) {
            return 0;
        }

        $maintenant = now();

        return QuestionConsumption::query()->insertOrIgnore(
            $items->map(fn ($item): array => [
                'user_id' => $user->getKey(),
                'attempt_id' => $attempt->getKey(),
                'item_id' => $item->getKey(),
                'access_grant_id' => $gouvernante?->getKey(),
                'consumed_at' => $maintenant,
            ])->all()
        );
    }

    /**
     * CE QUE COÛTE UNE SÉRIE, ET CE QU'IL RESTE APRÈS — pas 4.
     *
     * « Cette série utilise 10 de vos 12 questions restantes. » Sans cette
     * phrase, une seconde série qui rend 2 items au lieu de 10 est vécue comme
     * un défaut. L'annonce fait partie de la règle, pas de l'ornement.
     *
     * TOUT EST DÉRIVÉ, RIEN N'EST TRANSPORTÉ. Le coût est le nombre de lignes
     * de débit de cette tentative ; le reliquat est celui de l'enveloppe qui
     * gouverne à cet instant. Une reprise rend donc les mêmes nombres qu'une
     * ouverture, sans que rien n'ait à se souvenir de ce qui s'est passé —
     * et un chiffre relisible ne peut pas diverger de lui-même.
     *
     * `remaining` est NUL quand la consommation est libre. Un nombre y serait
     * faux, pas seulement inutile : il ferait croire à une limite.
     *
     * @return array<string, mixed>
     */
    public function annoncePour(User $user, Attempt $attempt, Exam $exam): array
    {
        $cout = QuestionConsumption::query()->where('attempt_id', $attempt->getKey())->count();
        $reliquat = $this->plafond($user, $exam);

        return [
            'cost' => $cout,
            'unlimited' => $reliquat === null,
            'remaining' => $reliquat,
            'notice' => $reliquat === null
                ? __('parcours.cout_sans_enveloppe')
                : __('parcours.cout_annonce', ['cout' => $cout, 'reliquat' => $cout + $reliquat]),
        ];
    }

    /**
     * COMPTER FAUX EST PIRE QUE REFUSER.
     *
     * La lecture est BRUTE, sans passer par la conversion d'énumération : le
     * jour où la base portera une seconde fenêtre, un modèle qui refuse de la
     * convertir lèverait une erreur technique là où le domaine veut un refus
     * qui se nomme.
     */
    public function assertFenetreImplementee(AccessGrantRecord $droit): void
    {
        $fenetre = $droit->getAttributes()['quota_periodicity'] ?? null;

        if ($fenetre !== null && $fenetre !== self::FENETRE_IMPLEMENTEE->value) {
            throw new PeriodiciteNonImplementee((string) $fenetre);
        }
    }

    /**
     * Les uuid dont un droit doit porter la portée pour couvrir cette épreuve.
     *
     * C'est la chaîne d'ascendance de `DatabaseAccessGrant::examChain()`, moins
     * le maillon « aucune portée » que l'appelant traite à part. Un droit à
     * portée de NŒUD n'y figure pas, et c'est juste : il couvre un chapitre,
     * pas l'épreuve entière, et une série d'épreuve n'est pas sa demande.
     *
     * @return list<string>
     */
    private function ascendance(Exam $exam): array
    {
        $ligne = DB::table('exams as epreuve')
            ->join('tracks as parcours', 'parcours.id', '=', 'epreuve.track_id')
            ->join('exam_families as famille', 'famille.id', '=', 'parcours.exam_family_id')
            ->join('filieres as filiere', 'filiere.id', '=', 'famille.filiere_id')
            ->leftJoin('audiences as public', 'public.id', '=', 'famille.audience_id')
            ->where('epreuve.id', $exam->getKey())
            ->select(
                'epreuve.uuid as epreuve',
                'famille.uuid as famille',
                'filiere.uuid as filiere',
                'public.uuid as public',
            )
            ->first();

        return array_values(array_filter([
            $ligne?->epreuve, $ligne?->famille, $ligne?->filiere, $ligne?->public,
        ]));
    }
}
