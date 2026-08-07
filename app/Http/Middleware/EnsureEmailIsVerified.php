<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocage par vérification d'e-mail, piloté par configuration.
 *
 * Décision OptimGov : bloquant dès l'inscription. Le seuil est externalisé
 * dans config/naja7i.php pour pouvoir être déplacé sans modifier le code si
 * le pilote révèle un décrochage d'activation.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next, ?string $stage = null): Response
    {
        $gate = config('naja7i.email_verification_gate');
        $user = $request->user();

        if ($user === null || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        $blocks = match ($gate) {
            'registration' => true,
            'after_diagnostic' => $stage !== 'diagnostic',
            'never' => in_array($stage, ['purchase', 'certification'], true),
            default => true,
        };

        if (! $blocks) {
            return $next($request);
        }

        return ApiError::make(
            'AUTH_EMAIL_NOT_VERIFIED',
            __('auth.email_not_verified'),
            403,
            ['resend_endpoint' => '/api/v1/auth/email/resend']
        );
    }
}
