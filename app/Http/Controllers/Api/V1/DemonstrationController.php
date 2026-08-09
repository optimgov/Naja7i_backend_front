<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Démonstration publique (fiche F03, décision du 8 août).
 *
 * Un visiteur sans compte voit une correction complète — question, quatre
 * justifications, cause étiquetée — mais elle est identifiée comme un EXEMPLE
 * dans la réponse d'API elle-même, pas seulement à l'écran.
 *
 * Sans ce marqueur, l'interface pourrait un jour la présenter comme le
 * résultat du visiteur, et lui attribuer une erreur qu'il n'a pas commise.
 */
class DemonstrationController extends Controller
{
    public function correction(Request $request): JsonResponse
    {
        $locale = in_array($request->query('locale'), ['fr', 'ar'], true)
            ? $request->query('locale')
            : 'fr';

        /* Une question publiée, éligible au diagnostic — donc dont tous les
         * distracteurs sont étiquetés (garde F03 du PAS-5). C'est justement
         * cette garantie qui rend la démonstration possible. */
        $question = Question::forDiagnostic()
            ->where('locale', $locale)
            ->with(['options', 'remediation', 'node', 'exam'])
            ->inRandomOrder()
            ->first();

        if ($question === null) {
            return ApiError::make(
                'DEMO_NOT_AVAILABLE',
                __('parcours.demo_indisponible'),
                404
            );
        }

        return response()->json([
            'data' => [
                'is_example' => true,          // marqueur contractuel
                'question' => [
                    'uuid' => $question->uuid,
                    'stem' => $question->stem,
                    'explanation' => $question->explanation,
                ],
                'options' => $question->options->map(fn ($o) => [
                    'position' => $o->position,
                    'content' => $o->content,
                    'is_correct' => $o->is_correct,
                    'rationale' => $o->rationale,
                    'cause' => $o->cause,
                ])->values(),
                'competency' => [
                    'code' => $question->node?->code,
                    'name' => $question->node?->localized('name'),
                ],
                'exam' => [
                    'code' => $question->exam?->code,
                    'name' => $question->exam?->localized('name'),
                    'coefficient' => $question->exam?->coefficient,
                ],
                'remediation' => $question->remediation === null ? null : [
                    'title' => $question->remediation->title,
                    'estimated_minutes' => $question->remediation->estimated_minutes,
                ],
            ],
            'meta' => [
                'notice' => __('parcours.demo_avertissement'),
            ],
        ]);
    }
}
