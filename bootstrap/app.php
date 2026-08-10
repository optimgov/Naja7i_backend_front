<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureBffRequestsAreStateful;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // AssignRequestId en TÊTE : une erreur survenant n'importe où — y
        // compris dans les middlewares suivants — doit pouvoir s'y référer.
        //
        // Vient ensuite l'authentification par COOKIES de session (ADR-0004) :
        // aucun jeton bearer, le seul client de cette API est le BFF Nitro qui
        // relaie la session du navigateur. On prépose la variante BFF plutôt
        // que d'appeler `statefulApi()` — voir EnsureBffRequestsAreStateful,
        // qui explique pourquoi la règle d'origine de Sanctum ne convient pas
        // à cette topologie.
        $middleware->api(prepend: [
            AssignRequestId::class,
            EnsureBffRequestsAreStateful::class,
        ]);

        // ResolveTenant : aucune requête métier ne s'exécute sans tenant résolu
        // (PAS-1, ADR-0002). SetLocale vient APRÈS l'authentification, il lit
        // la préférence du compte via $request->user().
        $middleware->api(append: [ResolveTenant::class, SetLocale::class]);

        /* `permission:<code>` porte l'autorisation fine de l'ADR-0009. Elle est
         * déclarée sur la ROUTE et non dans la méthode : une action protégée
         * doit l'être avant que le contrôleur ne s'exécute, et le contrôle
         * reste lisible depuis la table des routes. */
        $middleware->alias([
            'verified.api' => EnsureEmailIsVerified::class,
            'permission' => RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Toute erreur d'une requête JSON sort au format ErrorResponse, avec
        // son request_id — y compris 401, 404, 422, 429 et 500. C'est cet
        // identifiant qui relie une plainte candidat à une trace serveur.
        $exceptions->render(
            fn (Throwable $e, Request $request) => ApiExceptionRenderer::render($e, $request)
        );
    })->create();
