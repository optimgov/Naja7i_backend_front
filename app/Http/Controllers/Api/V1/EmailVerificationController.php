<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $verification) {}

    /**
     * Consomme le jeton posté par le frontend.
     * Route PUBLIQUE : le candidat clique souvent le lien dans un autre
     * navigateur (celui de son application mail), donc sans session ouverte.
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64'],
        ]);

        $user = $this->verification->consume($validated['token']);

        if ($user === null) {
            return ApiError::make(
                'AUTH_VERIFICATION_TOKEN_INVALID',
                __('auth.verification_token_invalid'),
                422,
                ['resend_endpoint' => '/api/v1/auth/email/resend']
            );
        }

        return (new UserResource($user->load('memberships.role')))->response();
    }

    /**
     * Renvoie un lien. Route PUBLIQUE et volontairement muette : elle répond
     * toujours 202, que l'adresse existe ou non. Une réponse différenciée
     * transformerait cet endpoint en outil d'énumération de comptes.
     */
    public function resend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc'],
        ]);

        $email = Str::lower(trim($validated['email']));
        $key = 'verify-resend:'.hash('sha256', $email);

        // Limite stricte : c'est un endpoint qui envoie des e-mails à une
        // adresse fournie par l'appelant, donc une arme de harcèlement
        // potentielle si on ne le borne pas.
        if (RateLimiter::tooManyAttempts($key, maxAttempts: 3)) {
            return ApiError::make(
                'RATE_LIMIT_EXCEEDED',
                __('auth.throttled', ['seconds' => RateLimiter::availableIn($key)]),
                429
            );
        }

        RateLimiter::hit($key, decaySeconds: 900);

        $user = User::where('email', $email)->first();

        if ($user !== null && $user->email_verified_at === null) {
            $this->verification->send($user);
        }

        return response()->json([
            'data' => ['message' => __('auth.verification_link_sent')],
        ], 202);
    }
}
