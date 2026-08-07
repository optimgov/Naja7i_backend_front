<?php

namespace App\Services;

use App\Models\Identity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Inscription d'un candidat B2C.
 *
 * Une seule transaction : un compte sans identité, sans rôle ou sans
 * acceptation des CGU serait invalide. Il ne doit jamais pouvoir exister,
 * même si une étape échoue en cours de route.
 */
final class RegistrationService
{
    public function __construct(private readonly LegalConsentService $legal) {}

    public function register(
        string $email,
        string $password,
        string $locale,
        bool $marketingGranted,
        Request $request,
    ): User {
        return DB::transaction(function () use ($email, $password, $locale, $marketingGranted, $request) {
            $user = User::create([
                'email' => $email,
                'password' => $password,      // hashé par le cast du modèle
                'locale' => $locale,
                'status' => 'active',
            ]);

            Identity::create([
                'user_id' => $user->id,
                'provider' => 'password',
                'last_used_at' => now(),
            ]);

            $user->grantCandidateRole();      // exige le contexte plateforme (PAS-1.1)

            // Trois actes de nature juridique distincte (ADR-0005).
            $this->legal->recordTermsAcceptance($user, $locale, $request);
            $this->legal->recordPrivacyAcknowledgement($user, $locale, $request);
            $this->legal->setMarketing($user, $marketingGranted, $locale, $request);

            return $user;
        });
    }
}
