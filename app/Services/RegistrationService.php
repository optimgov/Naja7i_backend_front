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
    public function __construct(
        private readonly LegalConsentService $legal,
        private readonly OffreGratuiteService $gratuite,
    ) {}

    public function register(
        string $firstName,
        string $lastName,
        string $academicLevel,
        string $address,
        string $email,
        string $password,
        string $locale,
        bool $marketingGranted,
        Request $request,
    ): User {
        return DB::transaction(function () use ($firstName, $lastName, $academicLevel, $address, $email, $password, $locale, $marketingGranted, $request) {
            $user = User::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'academic_level' => $academicLevel,
                'address' => $address,
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

            /*
             * LE PALIER GRATUIT FAIT PARTIE DE CE QU'EST UN COMPTE CANDIDAT.
             *
             * Il est attribué ICI, dans la transaction d'inscription, pour la
             * raison que ce service énonce déjà : « un compte sans identité,
             * sans rôle ou sans acceptation des CGU serait invalide ». Un compte
             * sans son palier gratuit l'est de la même façon — l'ADR-0025 fait
             * du gratuit un DROIT porté par une offre, pas une tolérance
             * appliquée à qui n'a rien.
             *
             * Pas à la vérification d'e-mail : celle-ci est une PORTE SUR
             * L'USAGE, pas un changement de nature du compte. La poser là
             * laisserait un compte créé et vérifié par un autre chemin sans son
             * palier, et obligerait le rattrapage à courir derrière.
             */
            $this->gratuite->attribuer($user);

            return $user;
        });
    }
}
