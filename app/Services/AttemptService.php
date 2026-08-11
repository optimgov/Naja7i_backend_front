<?php

namespace App\Services;

use App\Exceptions\IdempotencyKeyReused;
use App\Exceptions\MirrorAlreadyOpen;
use App\Exceptions\MirrorNotApplicable;
use App\Exceptions\NoMirrorAvailable;
use App\Exceptions\NoSiblingQuestionAvailable;
use App\Exceptions\NothingDueForReview;
use App\Exceptions\TrainingScopeTooNarrow;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\Exam;
use App\Models\QuestionOption;
use App\Models\Response;
use App\Models\User;
use App\Support\UniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cycle de vie d'une tentative.
 *
 * CONTRE-REVUE BLOC-1 — la course réponse/soumission était toujours ouverte,
 * après deux tentatives de correction.
 *
 * Ce qui n'allait pas : `answer()` lisait l'état de la tentative AVANT la
 * transaction, puis ne verrouillait que l'item. `submit()` verrouillait la
 * tentative. Les deux ne se disputaient donc jamais la même ligne :
 *
 *     A lit « in_progress »
 *     B verrouille la tentative, corrige, clôt
 *     A entre en transaction, verrouille l'item, écrit
 *     → une réponse existe après une correction qui l'ignore
 *
 * Et le test censé le couvrir était séquentiel : il soumettait entièrement
 * avant d'appeler `answer()`. Il vérifiait « une tentative close refuse une
 * réponse », pas l'entrelacement.
 *
 * Correction : `answer()` verrouille et RELIT la tentative en tête de
 * transaction, puis vérifie son état sous verrou. L'ordre de verrouillage est
 * identique dans les deux méthodes — tentative, puis items — pour qu'aucun
 * interblocage ne remplace la course.
 */
final class AttemptService
{
    public function __construct(
        private readonly DiagnosticComposer $composer,
        private readonly TrainingComposer $training,
        private readonly ReviewComposer $reviews,
        private readonly MemoryScheduler $memory,
        private readonly MasteryCalculator $mastery,
        private readonly QuestionsSoeurs $soeurs,
    ) {}

    /**
     * Empreinte de l'OPÉRATION demandée sous une clé d'idempotence.
     *
     * Une clé identifie une opération, pas un utilisateur. Sans empreinte, la
     * clé d'un diagnostic réutilisée pour ouvrir un entraînement rendait le
     * diagnostic — et les gardes d'ouverture (« rien à réviser », « périmètre
     * trop étroit ») se contournaient par restitution silencieuse, n'étant
     * jamais atteintes.
     *
     * N'entre ici que ce qui CHANGE LE RÉSULTAT. Le genre et l'épreuve d'abord ;
     * puis les paramètres propres à chaque chemin — le nombre demandé, le
     * périmètre de nœuds. La locale n'y figure pas : elle vient du profil du
     * candidat, pas de la requête.
     *
     * @param  array<string, mixed>  $parametres
     */
    private function empreinte(string $kind, ?int $examId, array $parametres = []): string
    {
        ksort($parametres);

        return hash('sha256', json_encode([
            'kind' => $kind,
            'exam_id' => $examId,
            'parametres' => $parametres,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Tentative déjà ouverte sous cette clé, si la requête est LA MÊME.
     *
     * Une empreinte absente ne compare rien : les tentatives antérieures à la
     * migration n'en ont pas, et leur en inventer une supposerait de deviner
     * les paramètres d'appels déjà servis.
     *
     * @throws IdempotencyKeyReused
     */
    private function rejeu(User $user, string $cle, string $empreinte): ?Attempt
    {
        $existante = Attempt::where('user_id', $user->id)
            ->where('idempotency_key', $cle)
            ->first();

        if ($existante === null) {
            return null;
        }

        if ($existante->idempotency_fingerprint !== null
            && $existante->idempotency_fingerprint !== $empreinte) {
            throw new IdempotencyKeyReused($cle);
        }

        return $existante;
    }

    /**
     * Rend la tentative gagnante après une collision d'index, ou relance.
     *
     * Le contrôle « une session est-elle déjà ouverte ? » précède la création
     * et ne peut pas être atomique : entre la lecture et l'écriture, une
     * seconde requête passe. L'index partiel fait alors son travail EN BASE —
     * c'est lui qui garantit l'invariant — mais une violation d'index n'est pas
     * une erreur du candidat : c'est une reprise. Un double-clic doit rendre
     * 200 et la session existante, jamais 500.
     *
     * On ne rattrape que les index ATTENDUS, nommément : un `catch` large
     * avalerait une contrainte de contrôle ou une clé étrangère orpheline, et
     * le défaut ressortirait ailleurs, méconnaissable.
     *
     * AUDIT TOURNÉE 2, BLOC-3 — L'EMPREINTE SE REVALIDE AVANT TOUTE REPRISE.
     *
     * Ce rattrapage contournait la garde d'idempotence du BLOC-5 de la tournée
     * précédente : il relisait une ligne et la rendait SANS comparer son
     * empreinte. Deux requêtes différentes lancées ensemble sous la même clé ne
     * se voyaient pas au contrôle préalable ; l'une insérait, l'autre recevait
     * la tentative de l'AUTRE opération. Deux correctifs justes séparément se
     * composaient en défaut.
     *
     * La distinction que fait cette méthode :
     *
     *  - collision sur l'index de CLÉ — c'est le même identifiant d'opération,
     *    donc l'empreinte doit concorder. Sinon `IdempotencyKeyReused`, comme
     *    au contrôle préalable : une clé identifie une opération.
     *  - collision sur un index d'OUVERTURE — c'est une autre requête du même
     *    candidat, légitimement en cours. On rend la session gagnante, et
     *    l'APPELANT juge si elle lui convient : l'entraînement et la révision
     *    reprennent n'importe quelle session ouverte de leur genre, le miroir
     *    non — sa charge utile décrit un item précis.
     *
     * @param  list<string>  $index
     *
     * @throws IdempotencyKeyReused
     */
    private function gagnante(QueryException $e, array $index, callable $relire, string $empreinte, string $cle): Attempt
    {
        if (! UniqueViolation::onAny($e, $index)) {
            throw $e;
        }

        $gagnante = $relire();

        if ($gagnante === null) {
            /* L'index a parlé mais la ligne est introuvable : elle a été close
             * ou supprimée entre-temps. Rien à rendre — on ne fabrique pas une
             * reprise qui n'existe pas. */
            throw $e;
        }

        if (UniqueViolation::on($e, 'attempts_tenant_user_idempotency_unique')
            && $gagnante->idempotency_fingerprint !== null
            && $gagnante->idempotency_fingerprint !== $empreinte) {
            throw new IdempotencyKeyReused($cle);
        }

        return $gagnante;
    }

    /** L'ouverture reprise correspond-elle à l'opération demandée ? */
    private function memeOperation(Attempt $attempt, string $empreinte): bool
    {
        return $attempt->idempotency_fingerprint === $empreinte;
    }

    public function startDiagnostic(
        User $user,
        Exam $exam,
        string $locale,
        string $idempotencyKey,
        int $total = 10,
        ?int $durationMinutes = null,
    ): Attempt {
        $empreinte = $this->empreinte('diagnostic', $exam->id, [
            'total' => $total,
            'duration_minutes' => $durationMinutes,
        ]);

        $existante = $this->rejeu($user, $idempotencyKey, $empreinte);

        if ($existante !== null) {
            return $existante;
        }

        $ouverte = fn () => Attempt::where('user_id', $user->id)
            ->where('exam_id', $exam->id)
            ->where('kind', 'diagnostic')
            ->open()
            ->first();

        $enCours = $ouverte();

        if ($enCours !== null && ! $enCours->hasExpired()) {
            return $enCours;
        }

        $questions = $this->composer->compose($exam, $locale, $total);

        if ($questions->count() < $total) {
            throw new RuntimeException(
                "Série incomplète : {$questions->count()} questions disponibles sur {$total} pour l'épreuve {$exam->code}."
            );
        }

        try {
            return DB::transaction(function () use ($user, $exam, $locale, $idempotencyKey, $empreinte, $questions, $durationMinutes) {
                $attempt = Attempt::create([
                    'user_id' => $user->id,
                    'exam_id' => $exam->id,
                    'locale' => $locale,
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_fingerprint' => $empreinte,
                    'kind' => 'diagnostic',
                    'status' => 'in_progress',
                    'started_at' => now(),
                    'last_activity_at' => now(),
                    'expires_at' => $durationMinutes ? now()->addMinutes($durationMinutes) : null,
                    'item_count' => $questions->count(),
                ]);

                foreach ($questions as $i => $question) {
                    AttemptItem::create([
                        'attempt_id' => $attempt->id,
                        'question_id' => $question->id,
                        'competency_node_id' => $question->competency_node_id,
                        'position' => $i + 1,
                    ]);
                }

                return $attempt->fresh('items');
            });
        } catch (QueryException $e) {
            // Deux ouvertures simultanées : l'index a tranché, on rend le gagnant.
            return $this->gagnante(
                $e,
                ['attempts_single_open_diagnostic', 'attempts_tenant_user_idempotency_unique'],
                fn () => $ouverte()?->load('items')
                    ?? Attempt::where('user_id', $user->id)
                        ->where('idempotency_key', $idempotencyKey)->first()?->load('items'),
                $empreinte,
                $idempotencyKey,
            );
        }
    }

    /**
     * Ouvre une session d'ENTRAÎNEMENT, ou rend celle déjà ouverte.
     *
     * Referme la boucle du produit : l'ordonnance recommandait quoi réviser,
     * sans que rien ne permette de le faire. Un plan à 90 jours sans activité
     * quotidienne n'est pas un plan.
     *
     * Trois différences avec `startDiagnostic`, toutes délibérées :
     *
     *  - AUCUN CHRONOMÈTRE. `expires_at` reste nul : l'entraînement n'est pas
     *    une épreuve. `secondsRemaining()` rend donc null sans traitement
     *    particulier, et la ressource le sert déjà tel quel.
     *  - UNE SEULE SESSION OUVERTE, tous concours confondus — l'unicité du
     *    diagnostic porte sur l'épreuve, celle-ci sur le candidat.
     *  - SÉRIE POSSIBLEMENT INCOMPLÈTE. On ne complète jamais hors périmètre ;
     *    l'appelant lit `item_count` et le compare à ce qu'il a demandé.
     *
     * @param  list<int>  $nodeIds  périmètre STRICT
     * @return array{attempt: Attempt, creee: bool, demande: int, disponibles: int, resservies: int}
     */
    public function startTraining(
        User $user,
        Exam $exam,
        array $nodeIds,
        string $locale,
        string $idempotencyKey,
        int $total = 15,
    ): array {
        $noeudsTries = $nodeIds;
        sort($noeudsTries);

        $empreinte = $this->empreinte('training', $exam->id, [
            'total' => $total,
            'node_ids' => $noeudsTries,
        ]);

        $existante = $this->rejeu($user, $idempotencyKey, $empreinte);

        if ($existante !== null) {
            return $this->reprise($existante, $total);
        }

        $ouverte = fn () => Attempt::where('user_id', $user->id)
            ->where('kind', 'training')
            ->open()
            ->first();

        $enCours = $ouverte();

        if ($enCours !== null) {
            return $this->reprise($enCours, $total);
        }

        $compose = $this->training->compose($exam, $user, $nodeIds, $locale, $total);
        $questions = $compose['questions'];

        /* En dessous du minime utile, on REFUSE. Servir deux questions sur un
         * point faible donnerait au candidat le sentiment d'avoir travaillé. */
        if ($questions->count() < TrainingComposer::MINIMUM_UTILE) {
            throw new TrainingScopeTooNarrow($compose['disponibles'], $questions->count());
        }

        try {
            $attempt = DB::transaction(function () use ($user, $exam, $locale, $idempotencyKey, $empreinte, $questions) {
                $attempt = Attempt::create([
                    'user_id' => $user->id,
                    'exam_id' => $exam->id,
                    'locale' => $locale,
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_fingerprint' => $empreinte,
                    'kind' => 'training',
                    'status' => 'in_progress',
                    'started_at' => now(),
                    'last_activity_at' => now(),
                    // Jamais d'échéance : ce n'est pas une épreuve.
                    'expires_at' => null,
                    'item_count' => $questions->count(),
                ]);

                foreach ($questions as $i => $question) {
                    AttemptItem::create([
                        'attempt_id' => $attempt->id,
                        'question_id' => $question->id,
                        'competency_node_id' => $question->competency_node_id,
                        'position' => $i + 1,
                    ]);
                }

                return $attempt->fresh('items');
            });
        } catch (QueryException $e) {
            return $this->reprise(
                $this->gagnante(
                    $e,
                    ['attempts_single_open_training', 'attempts_tenant_user_idempotency_unique'],
                    fn () => $ouverte() ?? Attempt::where('user_id', $user->id)
                        ->where('idempotency_key', $idempotencyKey)->first(),
                    $empreinte,
                    $idempotencyKey,
                ),
                $total,
            );
        }

        return [
            'attempt' => $attempt,
            /* Porté explicitement : `fresh()` rend une NOUVELLE instance, dont
             * `wasRecentlyCreated` est faux. S'y fier ferait répondre 200 à une
             * création, et un client ne saurait plus s'il ouvre ou reprend. */
            'creee' => true,
            'demande' => $total,
            'disponibles' => $compose['disponibles'],
            'resservies' => $compose['resservies'],
        ];
    }

    /**
     * Ouvre une session de RÉVISION, ou rend celle déjà ouverte.
     *
     * Troisième genre de tentative, et non un entraînement paramétré : le
     * périmètre ne vient ni des poids officiels ni d'un domaine faible, mais du
     * CALENDRIER — ce qui est échu aujourd'hui.
     *
     * Deux différences avec `startTraining`, toutes deux voulues :
     *
     *  - AUCUN MINIMUM UTILE. Un seul rendez-vous échu vaut une session : c'est
     *    ce que le calendrier a promis au candidat, et le refuser lui ferait
     *    perdre son palier pour cause de liste courte. `MINIMUM_UTILE` protège
     *    d'un entraînement qui n'apprend rien ; ici, on révise ce qui est dû.
     *  - RIEN D'ÉCHU EST UNE SITUATION NOMMÉE, pas une série vide. L'exception
     *    porte la prochaine échéance pour que le client sache quand revenir.
     *
     * @return array{attempt: Attempt, creee: bool, echus: int, servies: int, couverts: int, sans_question: int, resservies_identiques: int}
     *
     * @throws NothingDueForReview
     * @throws NoSiblingQuestionAvailable
     * @throws IdempotencyKeyReused
     */
    public function startReview(
        User $user,
        Exam $exam,
        string $locale,
        string $idempotencyKey,
        ?int $total = null,
    ): array {
        $total ??= MemoryScheduler::PLAFOND_LISTE;

        $empreinte = $this->empreinte('review', $exam->id, ['total' => $total]);

        $existante = $this->rejeu($user, $idempotencyKey, $empreinte);

        if ($existante !== null) {
            return $this->repriseDeRevision($user, $existante);
        }

        /* Une seule session de révision ouverte, tous concours confondus —
         * même portée que l'entraînement. Deux sessions serviraient deux fois
         * les mêmes rendez-vous, et la première soumise ferait avancer des
         * paliers que la seconde croirait encore en retard. */
        $ouverte = fn () => Attempt::where('user_id', $user->id)
            ->where('kind', 'review')
            ->open()
            ->first();

        $enCours = $ouverte();

        if ($enCours !== null) {
            return $this->repriseDeRevision($user, $enCours);
        }

        $echus = $this->memory->dueCount($user, $exam->id);

        if ($echus === 0) {
            throw new NothingDueForReview($this->memory->prochaineEcheance($user, $exam->id));
        }

        $compose = $this->reviews->compose(
            $exam,
            $this->memory->due($user, $exam->id),
            $locale,
            $total,
        );

        $questions = $compose['questions'];

        if ($questions->isEmpty()) {
            throw new NoSiblingQuestionAvailable($echus);
        }

        try {
            $attempt = DB::transaction(function () use ($user, $exam, $locale, $idempotencyKey, $empreinte, $questions) {
                $attempt = Attempt::create([
                    'user_id' => $user->id,
                    'exam_id' => $exam->id,
                    'locale' => $locale,
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_fingerprint' => $empreinte,
                    'kind' => 'review',
                    'status' => 'in_progress',
                    'started_at' => now(),
                    'last_activity_at' => now(),
                    // Réviser n'est pas une épreuve : jamais de chronomètre.
                    'expires_at' => null,
                    'item_count' => $questions->count(),
                ]);

                foreach ($questions as $i => $question) {
                    AttemptItem::create([
                        'attempt_id' => $attempt->id,
                        'question_id' => $question->id,
                        'competency_node_id' => $question->competency_node_id,
                        'position' => $i + 1,
                    ]);
                }

                return $attempt->fresh('items');
            });
        } catch (QueryException $e) {
            return $this->repriseDeRevision(
                $user,
                $this->gagnante(
                    $e,
                    ['attempts_single_open_review', 'attempts_tenant_user_idempotency_unique'],
                    fn () => $ouverte() ?? Attempt::where('user_id', $user->id)
                        ->where('idempotency_key', $idempotencyKey)->first(),
                    $empreinte,
                    $idempotencyKey,
                ),
            );
        }

        return [
            'attempt' => $attempt,
            'creee' => true,
            'echus' => $echus,
            'servies' => $questions->count(),
            'couverts' => $compose['couverts'],
            'sans_question' => $compose['sans_question'],
            'resservies_identiques' => $compose['resservies_identiques'],
        ];
    }

    /** @return array{attempt: Attempt, creee: bool, echus: int, servies: int, couverts: int, sans_question: int, resservies_identiques: int} */
    private function repriseDeRevision(User $user, Attempt $attempt): array
    {
        return [
            'attempt' => $attempt->load('items'),
            'creee' => false,
            'echus' => $attempt->exam_id === null ? 0 : $this->memory->dueCount($user, $attempt->exam_id),
            'servies' => $attempt->item_count,
            'couverts' => $attempt->item_count,
            'sans_question' => 0,
            'resservies_identiques' => 0,
        ];
    }

    /**
     * Ouvre la QUESTION MIROIR d'un item raté, ou rend celle déjà ouverte.
     *
     * F05. Après une erreur corrigée, on propose une AUTRE question portant le
     * même piège : sans elle, la correction est une lecture ; avec elle, c'est
     * une vérification.
     *
     * UNE TENTATIVE D'UN SEUL ITEM, ET CE N'EST PAS UN ARTIFICE. Le miroir
     * réemploie ainsi `answer()`, `submit()`, la correction, le recalcul de
     * maîtrise et la planification mémoire sans une ligne de plus. Et il
     * referme la boucle de F07 : réussir un miroir fait avancer le rendez-vous
     * du couple, ce qui est exactement le comportement voulu — c'est le même
     * piège évité sur un autre énoncé.
     *
     * LE MIROIR N'EST JAMAIS LA QUESTION DÉJÀ RÉPONDUE. La révision se rabat
     * sur l'énoncé connu faute de mieux ; ici ce serait absurde, et
     * `NoMirrorAvailable` le dit.
     *
     * @return array{attempt: Attempt, creee: bool, cause: string, question_source_uuid: string}
     *
     * @throws MirrorNotApplicable
     * @throws NoMirrorAvailable
     * @throws IdempotencyKeyReused
     */
    public function startMirror(User $user, AttemptItem $item, string $locale, string $idempotencyKey): array
    {
        $source = $item->attempt;

        if ($source->submitted_at === null) {
            throw new MirrorNotApplicable('la tentative d\'origine n\'est pas soumise');
        }

        $reponse = $item->response;
        $cause = $reponse?->selectedOption?->cause;

        /* Ni réponse juste, ni item sauté : on ne fait pas réviser ce qui n'a
         * jamais posé problème. Une bonne réponse ne porte d'ailleurs aucune
         * cause (contrainte du PAS-5), l'un implique l'autre. */
        if ($reponse === null || $reponse->is_correct !== false || $cause === null) {
            throw new MirrorNotApplicable('cet item ne porte aucune erreur diagnostiquée');
        }

        $empreinte = $this->empreinte('mirror', $source->exam_id, ['item_uuid' => $item->uuid]);

        $existante = $this->rejeu($user, $idempotencyKey, $empreinte);

        if ($existante !== null) {
            return $this->repriseDeMiroir($existante, $cause, $item);
        }

        /* Un seul miroir ouvert, tous concours confondus. En ouvrir un second
         * signifierait que le premier a été abandonné — on le reprend plutôt
         * que d'en effacer la trace. */
        $ouvert = fn () => Attempt::where('user_id', $user->id)
            ->where('kind', 'mirror')
            ->open()
            ->first();

        $enCours = $ouvert();

        if ($enCours !== null) {
            /* CELUI-CI, ou aucun. Un miroir décrit la vérification d'un item
             * précis : rendre celui d'un autre item servirait une question sans
             * rapport, sous la cause et la question source de CETTE demande. */
            if (! $this->memeOperation($enCours, $empreinte)) {
                throw new MirrorAlreadyOpen($enCours->uuid);
            }

            return $this->repriseDeMiroir($enCours, $cause, $item);
        }

        $exam = $source->exam;

        $vivier = $this->soeurs->vivier($exam, [$item->competency_node_id], $locale);

        $miroir = $this->soeurs
            ->autresQue($vivier, $item->competency_node_id, $cause, $item->question_id)
            ->first();

        if ($miroir === null) {
            throw new NoMirrorAvailable($cause);
        }

        try {
            $attempt = DB::transaction(function () use ($user, $exam, $locale, $idempotencyKey, $empreinte, $miroir) {
                $attempt = Attempt::create([
                    'user_id' => $user->id,
                    'exam_id' => $exam->id,
                    'locale' => $locale,
                    'idempotency_key' => $idempotencyKey,
                    'idempotency_fingerprint' => $empreinte,
                    'kind' => 'mirror',
                    'status' => 'in_progress',
                    'started_at' => now(),
                    'last_activity_at' => now(),
                    // Vérifier n'est pas passer une épreuve : aucun chronomètre.
                    'expires_at' => null,
                    'item_count' => 1,
                ]);

                AttemptItem::create([
                    'attempt_id' => $attempt->id,
                    'question_id' => $miroir->id,
                    'competency_node_id' => $miroir->competency_node_id,
                    'position' => 1,
                ]);

                return $attempt->fresh('items');
            });
        } catch (QueryException $e) {
            $gagnante = $this->gagnante(
                $e,
                ['attempts_single_open_mirror', 'attempts_tenant_user_idempotency_unique'],
                fn () => $ouvert() ?? Attempt::where('user_id', $user->id)
                    ->where('idempotency_key', $idempotencyKey)->first(),
                $empreinte,
                $idempotencyKey,
            );

            // Même règle que sur le chemin sans course : celui-ci, ou aucun.
            if (! $this->memeOperation($gagnante, $empreinte)) {
                throw new MirrorAlreadyOpen($gagnante->uuid);
            }

            return $this->repriseDeMiroir($gagnante, $cause, $item);
        }

        return [
            'attempt' => $attempt,
            'creee' => true,
            'cause' => $cause,
            'question_source_uuid' => $item->question->uuid,
        ];
    }

    /** @return array{attempt: Attempt, creee: bool, cause: string, question_source_uuid: string} */
    private function repriseDeMiroir(Attempt $attempt, string $cause, AttemptItem $item): array
    {
        return [
            'attempt' => $attempt->load('items'),
            'creee' => false,
            'cause' => $cause,
            'question_source_uuid' => $item->question->uuid,
        ];
    }

    /** Reprise d'une session ouverte : on rend l'existante, on n'en compose pas une seconde. */
    private function reprise(Attempt $attempt, int $demande): array
    {
        return [
            'attempt' => $attempt->load('items'),
            'creee' => false,
            'demande' => $demande,
            'disponibles' => $attempt->item_count,
            'resservies' => 0,
        ];
    }

    /**
     * Enregistre une réponse.
     *
     * Le contrôle préalable hors transaction est conservé pour produire un
     * message immédiat dans le cas courant — mais il ne DÉCIDE rien : la seule
     * vérification qui fait foi est celle effectuée sous verrou.
     */
    public function answer(
        AttemptItem $item,
        ?QuestionOption $option,
        string $confidence,
        ?int $elapsedMs = null,
        ?string $clientReportedAt = null,
    ): Response {
        if ($option !== null && $option->question_id !== $item->question_id) {
            throw new RuntimeException('L\'option choisie n\'appartient pas à la question présentée.');
        }

        return DB::transaction(function () use ($item, $option, $confidence, $elapsedMs, $clientReportedAt) {
            // 1. La tentative d'abord — même ordre que submit(), pas d'interblocage.
            $attempt = Attempt::where('id', $item->attempt_id)->lockForUpdate()->first();

            if ($attempt === null) {
                throw new RuntimeException('Tentative introuvable.');
            }

            // 2. L'état est relu sous verrou : une soumission concurrente est
            //    désormais visible, et elle gagne.
            if ($attempt->status !== 'in_progress') {
                throw new RuntimeException(
                    'Cette tentative est close : aucune réponse ne peut plus être enregistrée.'
                );
            }

            if ($attempt->hasExpired()) {
                throw new RuntimeException('Cette tentative a expiré.');
            }

            // 3. Puis l'item.
            $verrouille = AttemptItem::where('id', $item->id)->lockForUpdate()->first();
            $existante = Response::where('attempt_item_id', $item->id)->lockForUpdate()->first();

            $response = Response::updateOrCreate(
                ['attempt_item_id' => $item->id],
                [
                    'selected_option_id' => $option?->id,
                    'confidence' => $confidence,
                    'answered_at' => now(),
                    'client_reported_at' => $clientReportedAt,
                    'elapsed_ms' => $elapsedMs,
                ]
            );

            /*
             * LA DERNIÈRE ACTIVITÉ BOUGE À CHAQUE RÉPONSE, Y COMPRIS AU REJEU.
             *
             * Répondre écrit dans `responses` : sans cette écriture, la
             * tentative ne porterait aucune trace du travail du candidat, et un
             * écran de reprise classerait une série travaillée ce matin
             * derrière une série ouverte hier puis abandonnée.
             *
             * Rejouable au sens qui compte : rejouer la même réponse ne crée
             * rien et ne compte rien deux fois. Seule la date avance, et c'est
             * exact — le candidat vient bien de repasser sur cette question.
             * `answered_count`, lui, reste conditionné à la nouveauté.
             */
            if ($existante === null) {
                Attempt::where('id', $attempt->id)
                    ->increment('answered_count', 1, ['last_activity_at' => now()]);
            } else {
                Attempt::where('id', $attempt->id)->update(['last_activity_at' => now()]);
            }

            if ($verrouille !== null && $verrouille->presented_at === null) {
                AttemptItem::where('id', $item->id)->update(['presented_at' => now()]);
            }

            return $response;
        });
    }

    /**
     * Clôt la tentative, fige les corrections, PUIS en tire les conséquences.
     *
     * ORDRE DE VERROUILLAGE, UNE FOIS POUR TOUTES : tentative, puis items, puis
     * rendez-vous de révision. Le même dans `answer()`, dans `submit()` et dans
     * `MemoryScheduler`. Un ordre implicite finit toujours par s'inverser, et
     * l'interblocage qui en résulte ne se reproduit qu'en charge.
     *
     * LES EFFETS DE BORD VIVENT ICI, ET DERRIÈRE LA GARDE. Ils étaient dans
     * `ParcoursController::submit()`, appelés SANS CONDITION après un
     * `submit()` qui rend sans bruit une tentative déjà close. Conséquence
     * mesurée par l'audit : un client qui soumet, perd la réponse HTTP et
     * rejoue le même POST faisait avancer `consecutive_sure` DEUX fois. Deux
     * réussites certaines suffisant à sortir du calendrier, un simple renvoi
     * réseau pouvait vider un rendez-vous — et le garde « un couple ne bouge
     * qu'une fois par tentative » n'y pouvait rien : c'est un tableau en
     * mémoire, qui ne vit que le temps d'un appel.
     *
     * Ici, une tentative déjà close sort par le premier `return` et ne
     * déclenche plus rien. Le rejeu redevient ce qu'il doit être : sans effet.
     *
     * Ils sont aussi DANS la transaction, et le prix a été MESURÉ plutôt que
     * supposé : 41 ms et 42 requêtes pour une série de dix dont cinq erreurs —
     * le pire cas courant, chaque erreur ouvrant ou déplaçant un rendez-vous.
     * Une transaction de cet ordre ne tient aucun verrou disputé assez
     * longtemps pour compter : les lignes verrouillées sont celles du candidat
     * lui-même, que personne d'autre ne touche.
     *
     * Le repli, si ce coût devenait déraisonnable, serait un marqueur
     * persistant — une colonne enregistrant la tentative ayant fait bouger
     * chaque rendez-vous, qui rendrait le rejeu inoffensif sans allonger la
     * transaction. Il n'est pas nécessaire aujourd'hui, et il coûterait une
     * écriture de plus par rendez-vous.
     *
     * Le bénéfice immédiat est qu'une clôture partielle n'existe plus : soit la
     * tentative est close ET ses conséquences tirées, soit rien.
     *
     * Ferme DET-36.
     */
    public function submit(Attempt $attempt): Attempt
    {
        return DB::transaction(function () use ($attempt) {
            $verrouillee = Attempt::where('id', $attempt->id)->lockForUpdate()->first();

            if ($verrouillee === null || $verrouillee->status !== 'in_progress') {
                return $verrouillee ?? $attempt;   // rejeu, ou soumission concurrente
            }

            $justes = 0;

            $items = $verrouillee->items()
                ->lockForUpdate()
                ->with(['response.selectedOption'])
                ->get();

            foreach ($items as $item) {
                $response = $item->response;

                if ($response === null) {
                    continue;
                }

                $correcte = $response->selectedOption?->is_correct === true;
                $response->update(['is_correct' => $correcte]);

                if ($correcte) {
                    $justes++;
                }
            }

            $verrouillee->update([
                'status' => $verrouillee->hasExpired() ? 'expired' : 'submitted',
                'submitted_at' => now(),
                // Soumettre EST une activité : c'est la dernière de la série.
                'last_activity_at' => now(),
                'correct_count' => $justes,
            ]);

            $clos = $verrouillee->fresh();

            /* Une tentative sans épreuve ne nourrit ni maîtrise ni calendrier :
             * les deux sont indexés par épreuve. */
            if ($clos->exam !== null) {
                $this->mastery->recomputeForExam($clos->user, $clos->exam);
                $this->memory->planFromAttempt($clos);
            }

            return $clos;
        });
    }
}
