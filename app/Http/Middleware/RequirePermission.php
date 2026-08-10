<?php

namespace App\Http\Middleware;

use App\Services\PermissionResolver;
use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige une permission dans le tenant courant.
 *
 * REVUE PAS-9 BLOC-2 — le résolveur de permissions n'était consommé par aucun
 * contrôleur : le mécanisme existait, l'autorisation réelle reposait toujours
 * sur `hasRole()`. Le lot était annoncé « appliqué » à tort.
 *
 * Usage : ->middleware('permission:questions.publish')
 */
class RequirePermission
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function handle(Request $request, Closure $next, string ...$codes): Response
    {
        $user = $request->user();

        if ($user === null) {
            return ApiError::make('AUTH_UNAUTHENTICATED', __('auth.unauthenticated'), 401);
        }

        if (! $this->permissions->hasAny($user, $codes)) {
            return ApiError::make(
                'PERMISSION_DENIED',
                __('auth.permission_denied'),
                403,
                ['required' => array_values($codes)]
            );
        }

        return $next($request);
    }
}
