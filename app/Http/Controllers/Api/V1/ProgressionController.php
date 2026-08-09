<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\MasteryScore;
use App\Services\RemediationPlanner;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Maîtrise et ordonnance du candidat.
 *
 * Le score ne sort JAMAIS sans son volume d'évidence : c'est structurel
 * (MasteryScore::toPublicArray), pas une convention d'affichage.
 */
class ProgressionController extends Controller
{
    public function __construct(private readonly RemediationPlanner $planner) {}

    /** Maîtrise par domaine et sous-domaine pour une épreuve. */
    public function mastery(Request $request, string $examCode): JsonResponse
    {
        $exam = Exam::published()->where('code', $examCode)->first();

        if ($exam === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        $scores = MasteryScore::where('user_id', $request->user()->id)
            ->where('exam_id', $exam->id)
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
