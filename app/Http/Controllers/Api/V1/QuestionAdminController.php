<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Services\QuestionTransitionService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Première action réellement protégée par une permission fine.
 *
 * REVUE PAS-9 BLOC-2 : sans au moins un endpoint consommant le résolveur, les
 * dix-neuf permissions et leur trigger restaient verts sans effet sur
 * l'autorisation réelle. Cet endpoint referme l'écart et sert de gabarit aux
 * actions du back-office.
 *
 * L'autorisation est portée par le middleware `permission:questions.publish` —
 * pas par un contrôle dans la méthode, pour que le refus survienne avant toute
 * logique métier.
 */
class QuestionAdminController extends Controller
{
    public function __construct(private readonly QuestionTransitionService $transitions) {}

    public function publish(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'for_diagnostic' => ['sometimes', 'boolean'],
            'for_simulation' => ['sometimes', 'boolean'],
        ]);

        $question = Question::where('uuid', $uuid)->first();

        if ($question === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        try {
            $publiee = $this->transitions->publish(
                $question,
                forDiagnostic: (bool) ($validated['for_diagnostic'] ?? false),
                forSimulation: (bool) ($validated['for_simulation'] ?? false),
            );
        } catch (RuntimeException $e) {
            return ApiError::make('QUESTION_NOT_PUBLISHABLE', $e->getMessage(), 422);
        }

        return response()->json([
            'data' => [
                'uuid' => $publiee->uuid,
                'status' => $publiee->status,
                'published_at' => $publiee->published_at?->toIso8601String(),
                'eligible_for_diagnostic' => $publiee->eligible_for_diagnostic,
                'eligible_for_simulation' => $publiee->eligible_for_simulation,
            ],
        ]);
    }

    public function retire(Request $request, string $uuid): JsonResponse
    {
        $question = Question::where('uuid', $uuid)->first();

        if ($question === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        try {
            $retiree = $this->transitions->retire($question);
        } catch (RuntimeException $e) {
            return ApiError::make('QUESTION_NOT_RETIRABLE', $e->getMessage(), 422);
        }

        return response()->json(['data' => ['uuid' => $retiree->uuid, 'status' => $retiree->status]]);
    }
}
