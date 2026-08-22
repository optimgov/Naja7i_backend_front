<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\AccessGrant;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\MasteryScore;
use App\Services\RemediationPlanner;
use App\Support\ApiError;
use App\Support\MurPayant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Maîtrise et ordonnance du candidat.
 *
 * Le score ne sort JAMAIS sans son volume d'évidence : c'est structurel
 * (MasteryScore::toPublicArray), pas une convention d'affichage.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * DEUX MURS QUI NE SE RESSEMBLENT PAS — lot 3A.9
 *
 * La MESURE et la PRESCRIPTION ne se ferment pas de la même façon, et c'est la
 * décision de fond du lot.
 *
 *   · La carte de maîtrise est une mesure. Elle se rend TOUJOURS, mais à la
 *     profondeur que le palier ouvre : racine pour tout le monde, détail par
 *     matière et par chapitre avec `mastery.detail`. A-02 vend la GRANULARITÉ,
 *     pas l'existence de la mesure ; fermer la carte entièrement rendrait le
 *     produit signature invisible avant l'achat, et le seuil d'évidence
 *     (`MasteryScore::SEUIL_FAIBLE`) limite déjà naturellement ce qu'un compte
 *     d'essai peut y voir.
 *   · L'ordonnance est une prescription — c'est le service vendu. Sans
 *     `remediation.plan`, le champ n'est pas dans le rendu. Il n'y a rien à
 *     conserver : elle est dérivée à la demande, jamais stockée.
 *
 * ET L'EXPIRATION NE FERME QUE LA RESTITUTION, JAMAIS LE CALCUL.
 * `MasteryCalculator` continue d'écrire à chaque soumission, quel que soit le
 * palier : un score périmé présenté comme courant serait un chiffre faux, ce
 * que le produit refuse partout ailleurs.
 */
class ProgressionController extends Controller
{
    public function __construct(
        private readonly RemediationPlanner $planner,
        private readonly AccessGrant $access,
    ) {}

    /** Maîtrise par domaine et sous-domaine pour une épreuve. */
    public function mastery(Request $request, string $examCode): JsonResponse
    {
        $exam = Exam::published()->where('code', $examCode)->first();

        if ($exam === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        $detail = MurPayant::ouvre(
            $this->access, $request->user(), AccessGrant::MASTERY_DETAIL, $exam,
        );

        $scores = MasteryScore::where('user_id', $request->user()->id)
            ->where('exam_id', $exam->id)
            /* LA RESTITUTION GRADUÉE. Sans `mastery.detail`, la carte s'arrête
             * aux racines — les mêmes que `examSummary` appelle domaines. Ce
             * n'est pas un masquage d'affichage : les nœuds profonds ne
             * quittent pas le serveur. */
            ->when(! $detail, fn (Builder $q) => $q->whereHas(
                'node', fn (Builder $noeud) => $noeud->whereNull('parent_id'),
            ))
            ->with('node')
            ->get()
            ->sortBy(fn (MasteryScore $s) => [$s->node->depth, $s->node->position]);

        return response()->json([
            'data' => $scores->map(fn (MasteryScore $s) => array_merge(
                $s->toPublicArray(),
                [
                    'node_name' => $s->node->localized('name'),
                    'depth' => $s->node->depth,
                    'weight_percent' => (float) $s->node->weight_percent,
                ]
            ))->values(),
            'meta' => $this->planner->examSummary($request->user(), $exam),
        ]);
    }

    /** Ordonnance : quoi réviser, dans quel ordre, et pourquoi. */
    public function plan(Request $request, string $examCode): JsonResponse
    {
        $validated = $request->validate(['limit' => ['sometimes', 'integer', 'between:1,20']]);

        $exam = Exam::published()->where('code', $examCode)->first();

        if ($exam === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        /*
         * LE CHAMP DISPARAÎT — il ne se vide pas, il ne se grise pas.
         *
         * Pas de `data` vide, pas de compteur, pas de « il vous manque N
         * réponses » : un tableau vide se lit « nous n'avons rien trouvé pour
         * vous », ce qui est faux et décourageant. L'absence du champ se lit
         * « ce n'est pas dans votre accès », ce qui est vrai, et l'écran
         * d'abonnement dit la suite.
         *
         * L'avertissement de non-prédiction part avec : il qualifie une
         * ordonnance, et il n'y en a pas.
         */
        if (! MurPayant::ouvre($this->access, $request->user(), AccessGrant::REMEDIATION_PLAN, $exam)) {
            return response()->json(['meta' => ['exam_code' => $exam->code]]);
        }

        return response()->json([
            'data' => $this->planner->prioritize(
                $request->user(), $exam, $validated['limit'] ?? 5
            )->values(),
            'meta' => [
                'exam_code' => $exam->code,
                // Rappel explicite dans le contrat : aucune prédiction n'est
                // produite ici, et aucune ne le sera (METHODE §7.3).
                'disclaimer' => __('parcours.aucune_prediction'),
            ],
        ]);
    }
}
