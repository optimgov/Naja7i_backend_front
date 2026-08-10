<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogueController;
use App\Http\Controllers\Api\V1\DemonstrationController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\LegalController;
use App\Http\Controllers\Api\V1\ParcoursController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\ProgressionController;
use App\Http\Controllers\Api\V1\QuestionAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — front-office candidat
|--------------------------------------------------------------------------
| Authentification par cookies de session (BFF). AssignRequestId, ResolveTenant
| et SetLocale sont appliqués globalement au groupe api (bootstrap/app.php).
*/

Route::prefix('v1')->group(function () {

    // --- Public -----------------------------------------------------------
    Route::get('legal/documents', [LegalController::class, 'documents']);

    /*
     * Catalogue public (PAS-4) : aucune authentification, aucun tenant requis
     * pour lire. Ce sont ces routes qui alimentent les pages indexables par
     * Google — le levier d'acquisition du plan à 90 jours.
     */
    Route::prefix('catalogue')->group(function () {
        Route::get('/', [CatalogueController::class, 'index']);
        Route::get('calendrier', [CatalogueController::class, 'calendar']);

        Route::get('filieres/{slug}', [CatalogueController::class, 'filiere']);

        Route::get('familles/{slug}', [CatalogueController::class, 'family']);
        Route::get('familles/{slug}/competences', [CatalogueController::class, 'competencies']);

        // PAS-4.1 — Entrée de référence du référentiel : une matrice par
        // épreuve, chacune avec ses coefficients et ses poids (ADR-0014).
        Route::get('epreuves/{code}/competences', [CatalogueController::class, 'examCompetencies']);
        Route::get('familles/{famille}/specialites/{specialite}', [CatalogueController::class, 'specialty']);
    });

    /*
     * PAS-8 — Démonstration publique de la fiche F03. Une question d'exemple
     * avec sa correction complète, pour un visiteur sans compte : c'est la
     * vitrine de ce que le produit sait faire. La réponse porte un avertissement
     * disant que rien n'a été répondu ni enregistré (ADR-0018 §5).
     */
    Route::get('demonstration/correction', [DemonstrationController::class, 'correction'])
        ->middleware('throttle:30,1');

    // Vérification d'e-mail et mot de passe oublié : routes PUBLIQUES. Le
    // candidat clique le lien depuis son application de messagerie, souvent
    // dans un autre navigateur, donc sans session ouverte. Leur sécurité vient
    // du jeton opaque, pas de la session — et pas non plus d'une URL signée,
    // dont la signature ne survivrait pas au relais de Nitro (ADR-0008 §4).
    Route::post('auth/email/verify', [EmailVerificationController::class, 'verify'])
        ->middleware('throttle:10,1');

    Route::post('auth/email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1');

    Route::post('auth/password/request', [PasswordResetController::class, 'request'])
        ->middleware('throttle:6,1');

    Route::post('auth/password/reset', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:10,1');

    Route::middleware('guest')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
        Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    });

    // --- Session ouverte, e-mail pas nécessairement vérifié ----------------
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('me/legal', [LegalController::class, 'myEvents']);
        Route::patch('me/legal/marketing', [LegalController::class, 'updateMarketing']);

        // Refus explicite plutôt qu'un 404 déroutant.
        Route::patch('me/legal/terms', [LegalController::class, 'rejectNonRevocable']);
        Route::patch('me/legal/privacy', [LegalController::class, 'rejectNonRevocable']);
    });

    // --- Session + e-mail vérifié ------------------------------------------
    // Toutes les routes métier des pas suivants (entraînement, simulateur,
    // achat) viendront dans ce groupe.
    Route::middleware(['auth:sanctum', 'verified.api'])->group(function () {

        // PAS-8 — Parcours de diagnostic.
        Route::post('me/diagnostics/{examCode}', [ParcoursController::class, 'startDiagnostic'])
            ->middleware('throttle:10,1');

        Route::get('me/attempts/{uuid}', [ParcoursController::class, 'show']);

        Route::put('me/attempts/{uuid}/items/{itemUuid}', [ParcoursController::class, 'answer'])
            ->middleware('throttle:120,1');   // une réponse toutes les 30 s en rythme soutenu

        Route::post('me/attempts/{uuid}/submit', [ParcoursController::class, 'submit']);
        Route::get('me/attempts/{uuid}/correction', [ParcoursController::class, 'correction']);

        // PAS-8 — Restitution : maîtrise par compétence et ordonnance.
        Route::get('me/mastery/{examCode}', [ProgressionController::class, 'mastery']);
        Route::get('me/plan/{examCode}', [ProgressionController::class, 'plan']);
    });

    /*
     * PAS-11 — Première surface administrative.
     *
     * L'autorisation est portée par le middleware de permission, déclarée sur
     * la route : l'action est refusée avant que le contrôleur ne s'exécute, et
     * le contrôle reste lisible depuis `route:list`. Aucun `if` d'autorisation
     * dans les méthodes — c'est ce qui a manqué à l'ADR-0009 pendant neuf pas.
     */
    Route::middleware(['auth:sanctum', 'verified.api'])->prefix('admin')->group(function () {
        Route::post('questions/{uuid}/publish', [QuestionAdminController::class, 'publish'])
            ->middleware('permission:questions.publish');

        Route::post('questions/{uuid}/retire', [QuestionAdminController::class, 'retire'])
            ->middleware('permission:questions.retire');
    });
});
