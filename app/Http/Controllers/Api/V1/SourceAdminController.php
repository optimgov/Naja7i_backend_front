<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Source;
use App\Services\SourceVerificationService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contrôle documentaire des sources — DET-46.
 *
 * VÉRIFIER EST UN ACTE SUR LA SOURCE, PAS SUR LA QUESTION. Une source est citée
 * par plusieurs questions ; la vérifier une fois profite à toutes celles qui
 * s'y appuient. En faire un champ posé au fil d'une relecture de question
 * ferait recontrôler vingt fois le même arrêté, sans garantir que les vingt
 * verdicts concordent.
 *
 * `questions.review` est une permission de COMMODITÉ, pas de principe : le
 * relecteur a la source sous les yeux, et créer un rôle de documentaliste pour
 * une équipe qui n'en a pas serait de la cérémonie. Le jour où quelqu'un
 * vérifie des sources sans relire de questions, ce devient `sources.verify`.
 */
class SourceAdminController extends Controller
{
    public function __construct(private readonly SourceVerificationService $verification) {}

    public function verify(Request $request, string $uuid): JsonResponse
    {
        $source = Source::where('uuid', $uuid)->first();

        if ($source === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        $resultat = $this->verification->verifier($source, $request->user());

        return response()->json([
            'data' => [
                'uuid' => $resultat['source']->uuid,
                'code' => $resultat['source']->code,
                /* QUI et QUAND sont la valeur du champ. Une vérification
                 * anonyme n'engage personne, et une plateforme qui affirme que
                 * son contenu est sourcé doit pouvoir dire par qui. */
                'verified_at' => $resultat['source']->verified_at?->toIso8601String(),
                'verified_by_uuid' => $resultat['source']->verificateur?->uuid,
            ],
            'meta' => [
                /* Les citations des questions PUBLIÉES ne bougent pas : elles
                 * sont gelées, et la correction déjà servie s'appuyait sur
                 * l'état d'alors. */
                'citations_updated' => $resultat['citations_mises_a_jour'],
            ],
        ]);
    }
}
