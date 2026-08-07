<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Mot de passe oublié. S'appuie sur le broker Laravel et la table
 * `password_reset_tokens` restaurée au PAS-1.1.
 */
class PasswordResetController extends Controller
{
    /**
     * Demande de lien. Réponse TOUJOURS identique, que le compte existe ou
     * non : c'est la recommandation OWASP, et sans elle cet endpoint devient
     * un révélateur d'adresses inscrites.
     */
    public function request(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email:rfc']]);

        $email = Str::lower(trim($validated['email']));
        $key = 'pwd-reset:'.hash('sha256', $email);

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 3)) {
            return ApiError::make(
                'RATE_LIMIT_EXCEEDED',
                __('auth.throttled', ['seconds' => RateLimiter::availableIn($key)]),
                429
            );
        }

        RateLimiter::hit($key, decaySeconds: 900);

        // On ignore volontairement le statut retourné par le broker.
        Password::sendResetLink(['email' => $email]);

        return response()->json([
            'data' => ['message' => __('auth.reset_link_sent')],
        ], 202);
    }

    /** Application du nouveau mot de passe. */
    public function reset(Request $request): JsonResponse
    {
        $policy = config('naja7i.password');

        $rule = PasswordRule::min($policy['min_length'])->max($policy['max_length']);

        if ($policy['check_compromised']) {
            $rule = $rule->uncompromised();
        }

        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'confirmed', $rule],
        ]);

        $validated['email'] = Str::lower(trim($validated['email']));

        $status = Password::reset($validated, function ($user, string $password) {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),   // invalide les sessions « rester connecté »
            ])->save();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PasswordReset) {
            return ApiError::make(
                'AUTH_RESET_TOKEN_INVALID',
                __('auth.reset_token_invalid'),
                422
            );
        }

        return response()->json([
            'data' => ['message' => __('auth.password_updated')],
        ]);
    }
}
