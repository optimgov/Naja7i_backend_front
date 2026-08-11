<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\AccessGrant;
use App\Exceptions\IdempotencyKeyReused;
use App\Exceptions\NoSiblingQuestionAvailable;
use App\Exceptions\NothingDueForReview;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttemptResource;
use App\Http\Resources\ReviewScheduleResource;
use App\Models\Exam;
use App\Services\AttemptService;
use App\Services\CauseRevealService;
use App\Services\CouvertureBanque;
use App\Services\MemoryScheduler;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * F07 — Rendez-vous Mémoire : ce qui est dû aujourd'hui, et la séance.
 *
 * Contrôleur SÉPARÉ de `ParcoursController`, qui porte déjà le diagnostic,
 * l'entraînement, la passation et la correction. La révision est un module à
 * part entière — son entrée n'est ni une épreuve ni un domaine faible, mais une
 * date — et `ParcoursController` compte huit dépendances : lui en ajouter deux
 * de plus aurait fait grossir sans raison un fichier déjà long.
 *
 * Comme partout : une ressource d'un autre candidat est INTROUVABLE, jamais
 * interdite. Ici le filtre par `user_id` est porté par le planificateur, qui ne
 * lit jamais que les rendez-vous du demandeur.
 *
 * AUCUNE PRÉDICTION. Une date de prochaine revue, et rien d'autre : ni
 * probabilité de rétention, ni pourcentage de mémoire (METHODE §7.3).
 */
class MemoireController extends Controller
{
    public function __construct(
        private readonly MemoryScheduler $memory,
        private readonly AttemptService $attempts,
        private readonly AccessGrant $access,
        private readonly CauseRevealService $reveals,
        private readonly CouvertureBanque $couverture,
    ) {}

    /**
     * Ce qui est échu aujourd'hui.
     *
     * RIEN D'ÉCHU N'EST PAS UNE ERREUR. Liste vide, 200, et la date du prochain
     * rendez-vous : « rien aujourd'hui, prochain le 14 » est une information
     * utile, un 404 ne l'est pas — il ferait afficher un écran d'erreur à un
     * candidat parfaitement à jour.
     */
    public function due(Request $request, string $examCode): JsonResponse
    {
        $exam = Exam::published()->where('code', $examCode)->first();

        if ($exam === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        $user = $request->user();

        $echus = $this->memory->dueCount($user, $exam->id);
        $rendezVous = $echus === 0 ? collect() : $this->memory->due($user, $exam->id);

        $premium = $this->access->allows($user, AccessGrant::CAUSE_REVEAL);

        /* Une cause DÉJÀ PAYÉE reste ouverte, ici comme ailleurs : le produit
         * promet que revenir sur sa correction ne recoûte rien, et le compteur
         * n'est jamais remis à zéro pour cette raison. Rien n'est décompté à la
         * lecture d'une liste — on lit ce qui a déjà été acquis, on n'achète
         * pas. Ce qui n'a jamais été révélé reste fermé hors abonnement. */
        $deja = $premium ? [] : $this->reveals->revealedCouples($user);
        $prochaine = $this->memory->prochaineEcheance($user, $exam->id);

        return response()->json([
            'data' => $rendezVous
                ->map(fn ($rdv) => (new ReviewScheduleResource(
                    $rdv,
                    $premium || isset($deja[$rdv->competency_node_id.'|'.$rdv->cause]),
                ))->resolve())
                ->values(),
            'meta' => [
                'exam_code' => $exam->code,
                'due_total' => $echus,
                'served' => $rendezVous->count(),
                /* AUCUN PLAFOND SILENCIEUX. « 20 aujourd'hui, 47 en attente »
                 * dit au candidat où il en est ; à qui on cache 47, il croit
                 * avoir fini. */
                'pending' => max(0, $echus - $rendezVous->count()),
                'cap' => MemoryScheduler::PLAFOND_LISTE,
                'next_due_on' => $prochaine?->toDateString(),
                /* Rendez-vous échus que la banque ne sert que par l'énoncé déjà
                 * vu. UN NOMBRE, jamais le détail : nommer les couples
                 * nommerait des causes, et la cause est un champ payant. Le
                 * plan de rédaction correspondant est côté administration. */
                'without_sibling' => $echus === 0
                    ? 0
                    : $this->couverture->trousEchusDuCandidat($exam, $user, $user->locale),
            ],
        ]);
    }

    /**
     * Ouvre la séance de révision du jour, ou rend celle déjà ouverte.
     *
     * Même contrat que l'entraînement : 201 à l'ouverture, 200 à la reprise, et
     * une clé d'idempotence qui rend le rejeu sans effet.
     */
    public function startSession(Request $request, string $examCode): JsonResponse
    {
        $validated = $request->validate([
            'total' => ['sometimes', 'integer', 'between:1,'.MemoryScheduler::PLAFOND_LISTE],
        ]);

        $exam = Exam::published()->where('code', $examCode)->first();

        if ($exam === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        $user = $request->user();
        $cle = $request->header('Idempotency-Key') ?: (string) Str::uuid7();

        try {
            $session = $this->attempts->startReview(
                $user, $exam, $user->locale, $cle, $validated['total'] ?? null
            );
        } catch (IdempotencyKeyReused $e) {
            /* Refus explicite. Rendre la tentative préexistante contournerait
             * en silence la garde « rien à réviser » juste en dessous. */
            return ApiError::make(
                'IDEMPOTENCY_KEY_REUSED',
                __('parcours.cle_idempotence_reutilisee'),
                409,
            );
        } catch (NothingDueForReview $e) {
            /* Code DISTINCT de ceux du diagnostic et de l'entraînement : les
             * trois refus se ressemblent et n'appellent pas la même conduite.
             * Ici, il n'y a rien à corriger — le candidat est à jour. */
            return ApiError::make(
                'MEMORY_NOTHING_DUE',
                __('parcours.revision_rien_echu'),
                409,
                [
                    'exam_code' => $exam->code,
                    'next_due_on' => $e->prochaine?->toDateString(),
                ]
            );
        } catch (NoSiblingQuestionAvailable $e) {
            /* Le calendrier a du travail, c'est la BANQUE qui ne couvre pas
             * encore ces pièges. Rien à faire côté candidat, et inutile de lui
             * dire de revenir demain. */
            return ApiError::make(
                'MEMORY_NO_SIBLING_QUESTION',
                __('parcours.revision_sans_question_soeur'),
                409,
                ['exam_code' => $exam->code, 'due_total' => $e->echus]
            );
        }

        $attempt = $session['attempt'];

        return (new AttemptResource($attempt->load(['exam', 'items.question.options', 'items.response'])))
            ->additional(['meta' => [
                'due_total' => $session['echus'],
                'served' => $session['servies'],
                'pending' => max(0, $session['echus'] - $session['couverts']),
                'cap' => MemoryScheduler::PLAFOND_LISTE,
                /* Une question dont plusieurs distracteurs portent des causes
                 * échues en couvre plusieurs à la fois : `covered` dépasse donc
                 * `served`, et ce n'est pas une incohérence. */
                'covered' => $session['couverts'],
                /* Rendez-vous échus qu'AUCUNE question ne peut servir : la
                 * banque ne tend pas encore ce piège. Dit, jamais masqué. */
                'without_question' => $session['sans_question'],
                /* Rendez-vous servis par l'énoncé DÉJÀ VU, faute de sœur en
                 * banque. Le repli est assumé — sauter une échéance serait pire
                 * — mais il est annoncé, et il ne fait pas sortir du calendrier :
                 * reconnaître un énoncé n'est pas maîtriser une cause. */
                'reserved_identical' => $session['resservies_identiques'],
            ]])
            ->response()
            ->setStatusCode($session['creee'] ? 201 : 200);
    }
}
