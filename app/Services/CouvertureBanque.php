<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\Question;
use App\Models\ReviewSchedule;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Les trous de la banque, vus depuis ce que les candidats attendent.
 *
 * UN COUPLE SANS SŒUR EST UN TROU ÉDITORIAL, PAS UN CAS LIMITE À GÉRER.
 *
 * Quand un rendez-vous porte le couple (compétence, cause) et que la banque ne
 * compte qu'une seule question tendant ce piège, `ReviewComposer` ressert
 * l'énoncé déjà vu. Le code encaisse — le repli est annoncé, et la réussite qui
 * en découle ne fait plus sortir du calendrier ni monter le palier au-delà du
 * milieu de l'échelle. Mais encaisser n'est pas corriger : ce qui manque, c'est
 * une question.
 *
 * D'où cette lecture, et son ordre de tri : les couples RÉELLEMENT ATTENDUS par
 * des candidats d'abord. C'est un plan de rédaction gratuit — ces couples sont
 * exactement les questions qui manquent le plus au produit, établies par
 * l'usage plutôt que par une intuition de comité.
 *
 * ON PART DE LA DEMANDE, PAS DU CATALOGUE. Énumérer tous les couples possibles
 * — chaque compétence croisée avec les huit causes — produirait des milliers de
 * lignes dont personne n'a besoin. Un couple qui n'a jamais fait échouer
 * personne n'est pas un trou.
 */
/**
 * DET-71 — POURQUOI CETTE REQUÊTE NE COMPARE PLUS D'HORLOGES.
 *
 * Elle filtrait `q.published_at is not null and q.published_at <= now()` en
 * plus de `q.status = 'published'`. Un même fait — « cette question est
 * publiée » — avait donc DEUX sources de vérité, et les deux ont fini par
 * diverger.
 *
 * Le mécanisme, mesuré : `published_at` est écrit par PHP TRONQUÉ À LA SECONDE,
 * tandis que `now()` vaut `transaction_timestamp()` — figé à l'ouverture de la
 * transaction, avec ses microsecondes. Sous test, une question publiée juste
 * après une frontière de seconde portait donc une date FUTURE au regard de la
 * base et disparaissait du plan : `severity` passait de `no_sibling` à `none`,
 * et le test rougissait une fois sur N sans qu'on sache pourquoi.
 *
 * AUCUNE PUBLICATION DIFFÉRÉE N'EXISTE DANS CE PRODUIT — vérifié plutôt que
 * supposé : un seul écrivain (`QuestionTransitionService`, avec `now()`), la
 * colonne hors de l'assignation de masse, et zéro ligne future en base. La
 * condition ne protégeait donc rien : elle dupliquait le statut en y ajoutant
 * une horloge.
 *
 * Le jour où une publication programmée deviendra une fonction réelle, la
 * condition revient — mais avec `clock_timestamp()`, et pour cette fonction-là.
 */
final class CouvertureBanque
{
    /**
     * Une question ne fait pas une paire.
     *
     * En dessous de deux, `ReviewComposer` n'a pas le choix : il ressert
     * l'énoncé que le rendez-vous a déjà fait servir.
     */
    public const SOEURS_MINIMUM = 2;

    /** Langues du produit. Une question est monolingue : un trou l'est aussi. */
    private const LANGUES = ['fr', 'ar'];

    /**
     * Couples attendus que la banque ne couvre pas, du plus demandé au moins.
     *
     * @param  User|null  $user  restreint à un candidat, ou tous les candidats si nul
     * @return Collection<int, array<string, mixed>>
     */
    public function trous(Exam $exam, ?User $user = null): Collection
    {
        $lignes = $this->demande($exam, $user);

        return $lignes
            ->filter(fn (array $ligne) => $this->estUnTrou($ligne))
            ->sortByDesc(fn (array $ligne) => [$ligne['waiting_candidates'], -$ligne['node_id']])
            ->values()
            ->map(fn (array $ligne) => [
                'competency' => [
                    'code' => $ligne['node_code'],
                    'name_fr' => $ligne['node_name_fr'],
                    'name_ar' => $ligne['node_name_ar'],
                ],
                'cause' => $ligne['cause'],
                'waiting_candidates' => $ligne['waiting_candidates'],
                /* LA COUVERTURE EST PAR LANGUE, et ce n'est pas un détail de
                 * présentation. Une question est monolingue : « une sœur en
                 * français, aucune en arabe » désigne DEUX travaux de rédaction
                 * distincts, dont un seul est urgent. Une sévérité unique par
                 * couple les confondrait, et comme la banque arabe est encore
                 * vide, elle marquerait tout au rouge maximal — un plan de
                 * rédaction qui crie partout ne se lit plus. */
                'coverage' => $this->couvertureParLangue($ligne),
            ]);
    }

    /**
     * Combien de rendez-vous ÉCHUS d'un candidat n'ont pas de sœur.
     *
     * Sert la liste du candidat, qui ne reçoit qu'un NOMBRE : le détail
     * nommerait des causes, et la cause est un champ payant
     * (`ReviewScheduleResource`). On dit qu'il en existe, jamais lesquelles.
     */
    public function trousEchusDuCandidat(Exam $exam, User $user, string $locale): int
    {
        return $this->demande($exam, $user, echusSeulement: true)
            ->filter(fn (array $ligne) => $ligne["questions_{$locale}"] < self::SOEURS_MINIMUM)
            ->count();
    }

    /**
     * L'ÉPREUVE SUR LAQUELLE OUVRIR LE TABLEAU DE BORD — D-03.
     *
     * ══════════════════════════════════════════════════════════════════════
     * CE QUE LE DÉFAUT DISAIT DE L'ÉCRAN
     *
     * La page de couverture ouvrait sur `Exam::published()->orderBy('name_fr')
     * ->value('id')` : la PREMIÈRE ÉPREUVE PAR ORDRE ALPHABÉTIQUE. Sur le semis
     * réel, c'est « Didactique de la langue française » — une épreuve sans une
     * question et sans un candidat. La page répondait donc, sereinement :
     * « Aucun trou. Chaque couple attendu par un candidat est servi par au
     * moins deux questions. »
     *
     * L'affirmation était exacte et sans objet. La banque semée vit sur
     * « Spécialité — Langue française », et le premier écran du back-office
     * annonçait qu'il n'y avait rien à faire en regardant ailleurs.
     *
     * L'ORDRE ALPHABÉTIQUE N'EST PAS UN DÉFAUT D'INTERFACE, c'est une absence
     * de critère. Une page dont l'ordre est arbitraire finit toujours par
     * ouvrir sur le vide, et personne ne peut dire que c'est faux.
     *
     * LE CRITÈRE EST CELUI DE LA PAGE ELLE-MÊME : le travail à faire. On
     * classe par nombre de trous, puis par candidats en attente, puis par
     * volume de banque publiée. L'alphabet ne sert plus que de départage
     * final, quand tout le reste est à égalité — le plus souvent parce que
     * TOUT est à zéro, et il n'y a alors rien de mieux à dire.
     * ══════════════════════════════════════════════════════════════════════
     *
     * @return array{exam: Exam, trous: int, attente: int, questions: int}|null
     */
    public function epreuveAOuvrir(): ?array
    {
        $classement = Exam::published()
            ->orderBy('name_fr')
            ->get()
            ->map(function (Exam $exam): array {
                $trous = $this->trous($exam);

                return [
                    'exam' => $exam,
                    'trous' => $trous->count(),
                    'attente' => (int) $trous->sum('waiting_candidates'),
                    'questions' => Question::where('exam_id', $exam->id)
                        ->where('status', 'published')
                        ->count(),
                ];
            });

        if ($classement->isEmpty()) {
            return null;
        }

        /* `sortByDesc` de Laravel est STABLE : à critères égaux, l'ordre
         * alphabétique du `get()` ci-dessus survit. C'est le départage voulu,
         * et il ne se lit pas dans le tri lui-même — d'où cette ligne. */
        return $classement
            ->sortByDesc(fn (array $l) => [$l['trous'], $l['attente'], $l['questions']])
            ->first();
    }

    /**
     * La demande réelle : un couple par ligne, avec ce que la banque en offre.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function demande(Exam $exam, ?User $user, bool $echusSeulement = false): Collection
    {
        /* Piloté par `ReviewSchedule` pour que le scope global filtre
         * `review_schedules.tenant_id`. Les sous-requêtes, écrites à la main,
         * n'interrogent que le CATALOGUE — global par construction (ADR-0002) —
         * et n'ont donc pas de tenant à porter. */
        $requete = ReviewSchedule::query()
            ->join('competency_nodes', 'competency_nodes.id', '=', 'review_schedules.competency_node_id')
            ->where('review_schedules.exam_id', $exam->id)
            ->groupBy([
                'review_schedules.competency_node_id',
                'review_schedules.cause',
                'competency_nodes.code',
                'competency_nodes.name_fr',
                'competency_nodes.name_ar',
            ])
            ->selectRaw('review_schedules.competency_node_id as node_id')
            ->selectRaw('review_schedules.cause::text as cause')
            ->selectRaw('competency_nodes.code as node_code')
            ->selectRaw('competency_nodes.name_fr as node_name_fr')
            ->selectRaw('competency_nodes.name_ar as node_name_ar')
            ->selectRaw('count(distinct review_schedules.user_id) as waiting_candidates');

        foreach (self::LANGUES as $langue) {
            $requete->selectRaw(
                "({$this->comptageSql()}) as questions_{$langue}",
                [$exam->id, $langue]
            );
        }

        if ($user !== null) {
            $requete->where('review_schedules.user_id', $user->id);
        }

        if ($echusSeulement) {
            $requete->due();
        }

        return $requete->get()->map(fn ($ligne) => [
            'node_id' => (int) $ligne->node_id,
            'cause' => $ligne->cause,
            'node_code' => $ligne->node_code,
            'node_name_fr' => $ligne->node_name_fr,
            'node_name_ar' => $ligne->node_name_ar,
            'waiting_candidates' => (int) $ligne->waiting_candidates,
            'questions_fr' => (int) $ligne->questions_fr,
            'questions_ar' => (int) $ligne->questions_ar,
        ]);
    }

    /**
     * Questions publiées, éligibles, tendant CE piège dans CETTE compétence.
     *
     * Corrélée aux colonnes de regroupement : c'est ce qui permet de compter
     * par couple sans une requête par couple. Les conditions reproduisent
     * `Question::scopeForDiagnostic` — les recopier est le prix d'une
     * sous-requête corrélée, et un écart se verrait au premier trou fantôme.
     */
    private function comptageSql(): string
    {
        $publiable = Question::PUBLISHABLE;

        return <<<SQL
            select count(*) from questions q
             where q.competency_node_id = review_schedules.competency_node_id
               and q.exam_id = ?
               and q.locale = ?
               and q.status = '{$publiable}'
               and q.retired_at is null
               -- Pas de condition sur published_at : voir l'en-tête, DET-71.
               and q.eligible_for_diagnostic = true
               and exists (
                   select 1 from question_options o
                    where o.question_id = q.id
                      and o.is_correct = false
                      and o.cause = review_schedules.cause
               )
        SQL;
    }

    /** @param  array<string, mixed>  $ligne */
    private function estUnTrou(array $ligne): bool
    {
        foreach (self::LANGUES as $langue) {
            if ($ligne["questions_{$langue}"] < self::SOEURS_MINIMUM) {
                return true;
            }
        }

        return false;
    }

    /**
     * `none` : aucune question ne tend ce piège dans cette langue — la séance
     * ne peut même pas se composer. `no_sibling` : une seule, donc l'énoncé
     * revient à l'identique. `covered` : la langue n'a rien à faire ici.
     *
     * @param  array<string, mixed>  $ligne
     * @return array<string, array{published_questions: int, severity: string}>
     */
    private function couvertureParLangue(array $ligne): array
    {
        $couverture = [];

        foreach (self::LANGUES as $langue) {
            $compte = $ligne["questions_{$langue}"];

            $couverture[$langue] = [
                'published_questions' => $compte,
                'severity' => match (true) {
                    $compte === 0 => 'none',
                    $compte < self::SOEURS_MINIMUM => 'no_sibling',
                    default => 'covered',
                },
            ];
        }

        return $couverture;
    }
}
