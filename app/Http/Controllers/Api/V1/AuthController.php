<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\LegalConsentService;
use App\Services\LoginThrottle;
use App\Services\RegistrationService;
use App\Support\ApiError;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Authentification candidat — schéma COOKIES (BFF), pas bearer.
 *
 * Le navigateur ne parle qu'à www.naja7i.ma ; Nitro relaie vers l'API. La
 * session est un cookie httpOnly : aucun jeton n'est accessible au JavaScript
 * de la page, ce qui ferme la classe d'attaques XSS-vol-de-jeton (ADR-0004).
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registration,
        private readonly LegalConsentService $legal,
        private readonly LoginThrottle $throttle,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $email = Str::lower($request->string('email')->trim()->value());

        if (User::where('email', $email)->exists()) {
            return ApiError::make('AUTH_EMAIL_ALREADY_USED', __('auth.email_already_used'), 409);
        }

        $user = $this->registration->register(
            email: $email,
            password: $request->string('password')->value(),
            locale: $request->string('locale')->value(),
            marketingGranted: $request->boolean('marketing_granted'),
            request: $request,
        );

        event(new Registered($user));   // déclenche l'envoi du lien de vérification

        // La session est ouverte même si l'e-mail n'est pas vérifié : le
        // candidat doit pouvoir consulter son état et redemander l'envoi.
        // C'est le middleware EnsureEmailIsVerified qui bloque l'usage réel.
        // Garde nommé explicitement : `auth:sanctum` fait de « sanctum » le
        // garde par défaut du cycle, or celui-ci ne sait pas ouvrir de session
        // (c'est un RequestGuard, sans état). Ouvrir la session, c'est une
        // affaire du garde web, et le dire est plus sûr que de l'espérer.
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return (new UserResource($user->load('memberships.role')))
            ->response()
            ->setStatusCode(201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $email = Str::lower($request->string('email')->trim()->value());
        $ip = (string) $request->ip();

        $state = $this->throttle->check($email, $ip);

        if ($state['limited']) {
            return ApiError::make(
                'RATE_LIMIT_EXCEEDED',
                __('auth.throttled', ['seconds' => $state['retry_after']]),
                429
            );
        }

        $user = User::where('email', $email)->first();

        // Hash::check systématique, même sans compte : sans cela, l'écart de
        // temps de réponse révèle quelles adresses sont inscrites.
        $submitted = $request->string('password')->value();
        $valid = $user
            ? Hash::check($submitted, (string) $user->password)
            : Hash::check($submitted, self::dummyHash());

        if (! $user || ! $valid) {
            $this->throttle->hit($email, $ip);

            return ApiError::make('AUTH_INVALID_CREDENTIALS', __('auth.invalid_credentials'), 401);
        }

        if ($user->status !== 'active') {
            return ApiError::make('AUTH_ACCOUNT_SUSPENDED', __('auth.account_suspended'), 403);
        }

        $this->throttle->clear($email, $ip);

        Auth::guard('web')->login($user, remember: $request->boolean('remember'));
        $request->session()->regenerate();   // anti-fixation de session

        $user->identities()->where('provider', 'password')->update(['last_used_at' => now()]);

        return (new UserResource($user->load('memberships.role')))->response();
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // `auth:sanctum` a fait de « sanctum » le garde par défaut du cycle, et
        // ce garde retient l'utilisateur qu'il a résolu. Détruire la session ne
        // le vide pas : tout ce qui s'exécute APRÈS la déconnexion continuerait
        // de voir un candidat connecté — middlewares de sortie, écouteurs
        // terminables, et jusqu'à la requête suivante lorsque l'application
        // reste en mémoire (Octane, banc de test).
        Auth::forgetGuards();

        return response()->json(null, 204);
    }

    /**
     * Confirmation d'adresse depuis le lien reçu par e-mail.
     *
     * La comparaison de l'empreinte se fait en temps constant : sans cela, une
     * signature valide mais une empreinte fausse laisseraient deviner l'adresse
     * associée à un uuid, octet par octet.
     */
    public function verifyEmail(Request $request, string $uuid, string $hash): JsonResponse
    {
        $user = User::where('uuid', $uuid)->first();

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return ApiError::make(
                'AUTH_VERIFICATION_LINK_INVALID',
                __('auth.verification_link_invalid'),
                403
            );
        }

        // Idempotent : recliquer sur le lien ne doit pas produire une erreur.
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return response()->json(['data' => ['email_verified' => true]]);
    }

    /** Renvoi du lien de vérification au candidat connecté. */
    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['data' => ['email_verified' => true]]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(null, 202);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return (new UserResource($user->load('memberships.role')))
            ->additional([
                'meta' => [
                    // Actes juridiques en attente sur les versions PUBLIÉES :
                    // se remplit tout seul quand une nouvelle version paraît.
                    'pending_legal_actions' => $this->legal->pendingActions($user, $user->locale),
                    'email_verified' => $user->hasVerifiedEmail(),
                ],
            ])
            ->response();
    }

    /**
     * Empreinte factice, impossible à obtenir, servant à égaliser le temps de
     * réponse lorsqu'aucun compte ne correspond à l'adresse soumise.
     *
     * Elle est calculée avec le hasher COURANT, et non figée en dur : depuis
     * le passage à argon2id (PAS-2), un condensé bcrypt gravé dans le code ne
     * coûterait plus le même temps qu'une vérification réelle — le canal
     * auxiliaire que cette précaution ferme se rouvrirait sans que rien ne le
     * signale. Une seule empreinte par processus suffit : elle n'est jamais
     * comparée à autre chose qu'un mot de passe voué à échouer.
     */
    private static ?string $dummyHash = null;

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(Str::random(64));
    }
}
