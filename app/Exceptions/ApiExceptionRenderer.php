<?php

namespace App\Exceptions;

use App\Support\ApiError;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Traduction de TOUTE exception en ErrorResponse (contrat OpenAPI 3.1).
 *
 * Sans ce point de passage unique, Laravel produit trois formats différents
 * selon l'erreur — `{"message": "..."}` pour un 404, `{"message", "errors"}`
 * pour un 422, une page HTML pour un 500 — et aucun ne porte le `request_id`.
 * Le frontend devrait alors deviner la forme de ce qu'il reçoit, et le support
 * n'aurait rien pour relier une plainte candidat à une trace serveur.
 *
 * Les tests contractuels d'ApiContractTest vérifient la présence de
 * `request_id` sur chaque code d'erreur.
 */
final class ApiExceptionRenderer
{
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        // Les requêtes non-JSON (health check, futures pages servies par le
        // web) gardent le rendu Laravel par défaut.
        if (! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => self::validation($e),

            $e instanceof AuthenticationException => ApiError::make(
                'AUTH_UNAUTHENTICATED', __('auth.unauthenticated'), 401
            ),

            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ApiError::make(
                'RESOURCE_NOT_FOUND', __('errors.not_found'), 404
            ),

            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => ApiError::make(
                'AUTH_FORBIDDEN', __('errors.forbidden'), 403
            ),

            // On teste le CODE 419, pas TokenMismatchException : Laravel
            // convertit celle-ci en HttpException avant d'appeler les
            // callbacks de rendu. Un `instanceof TokenMismatchException` ici
            // ne se déclencherait jamais — la vérification manuelle du PAS-3
            // l'a montré, la session expirée sortait en « HTTP_ERROR » avec le
            // message anglais du framework.
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 419 => ApiError::make(
                'CSRF_TOKEN_MISMATCH', __('errors.csrf_mismatch'), 419
            ),

            // Avant HttpExceptionInterface : ThrottleRequestsException en hérite.
            $e instanceof ThrottleRequestsException => self::withHeaders(
                ApiError::make('RATE_LIMIT_EXCEEDED', __('errors.rate_limited'), 429),
                $e
            ),

            $e instanceof MethodNotAllowedHttpException => self::withHeaders(
                ApiError::make('METHOD_NOT_ALLOWED', __('errors.method_not_allowed'), 405),
                $e
            ),

            // Le message de l'exception n'est PAS repris tel quel : ces
            // exceptions viennent du framework et portent un texte anglais
            // écrit pour le développeur, pas pour le candidat. Il part dans
            // `details`, et seulement quand le mode debug est actif.
            $e instanceof HttpExceptionInterface => self::withHeaders(
                ApiError::make(
                    'HTTP_ERROR',
                    __('errors.internal'),
                    $e->getStatusCode(),
                    config('app.debug') && $e->getMessage() !== '' ? ['reason' => $e->getMessage()] : []
                ),
                $e
            ),

            default => self::internal($e),
        };
    }

    /**
     * 422 — deux représentations volontairement distinctes :
     *
     *  - `error.details` : une LISTE de couples champ/messages. Le contrat
     *    OpenAPI décrit ainsi une forme stable, sans clés dynamiques ; et
     *    surtout aucun nom de champ ne devient une clé JSON, ce que vérifie le
     *    test contractuel récursif (« password » figure parmi les clés
     *    interdites).
     *  - `errors` : le sac d'erreurs conventionnel de Laravel, que le frontend
     *    et les outils de test consomment directement.
     */
    private static function validation(ValidationException $e): JsonResponse
    {
        $errors = $e->errors();

        $details = [];

        foreach ($errors as $field => $messages) {
            $details[] = ['field' => $field, 'messages' => array_values($messages)];
        }

        $response = ApiError::make(
            'VALIDATION_FAILED',
            __('errors.validation_failed'),
            $e->status,
            $details
        );

        return $response->setData($response->getData(true) + ['errors' => $errors]);
    }

    private static function internal(Throwable $e): JsonResponse
    {
        // Le détail technique ne sort jamais en production : il servirait
        // d'abord à celui qui cherche une faille. En local, il évite d'avoir à
        // fouiller les journaux pour chaque test rouge.
        $details = config('app.debug')
            ? [
                'exception' => $e::class,
                'reason' => $e->getMessage(),
                'origin' => $e->getFile().':'.$e->getLine(),
            ]
            : [];

        return ApiError::make('INTERNAL_ERROR', __('errors.internal'), 500, $details);
    }

    /** Conserve les en-têtes portés par l'exception HTTP, dont Retry-After. */
    private static function withHeaders(JsonResponse $response, HttpExceptionInterface $e): JsonResponse
    {
        foreach ($e->getHeaders() as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
