<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\LegalController;
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

    // Le lien reçu par e-mail est cliqué dans un navigateur qui ne porte
    // aucune session : c'est la signature de l'URL qui authentifie, pas le
    // cookie. L'identifiant public (uuid) y figure, jamais la clé interne.
    Route::get('auth/email/verify/{uuid}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::middleware('guest')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
        Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    });

    // --- Session ouverte, e-mail pas nécessairement vérifié ----------------
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        // Renvoi du lien de vérification : l'adresse de cet endpoint est
        // annoncée par EnsureEmailIsVerified dans chacun de ses refus.
        Route::post('auth/email/resend', [AuthController::class, 'resendVerificationEmail'])
            ->middleware('throttle:3,1');

        Route::get('me/legal', [LegalController::class, 'myEvents']);
        Route::patch('me/legal/marketing', [LegalController::class, 'updateMarketing']);

        // Refus explicite plutôt qu'un 404 déroutant.
        Route::patch('me/legal/terms', [LegalController::class, 'rejectNonRevocable']);
        Route::patch('me/legal/privacy', [LegalController::class, 'rejectNonRevocable']);
    });

    // --- Session + e-mail vérifié ------------------------------------------
    // Toutes les routes métier des pas suivants (diagnostic, entraînement,
    // simulateur, achat) viendront dans ce groupe.
    Route::middleware(['auth:sanctum', 'verified.api'])->group(function () {
        // Placeholder volontaire : aucune route métier au PAS-2.
    });
});
