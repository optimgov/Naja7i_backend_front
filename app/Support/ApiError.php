<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Réponses d'erreur conformes au schéma ErrorResponse du contrat OpenAPI 3.1.
 * `request_id` est OBLIGATOIRE sur toutes les erreurs, y compris 401, 404,
 * 422, 429 et 500 : c'est lui qui relie une plainte candidat à une trace
 * serveur. Un test contractuel le vérifie sur chaque code.
 */
final class ApiError
{
    public static function make(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json([
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details ?: null,
                'request_id' => RequestId::current(),
            ], fn ($v) => $v !== null),
        ], $status);
    }
}
