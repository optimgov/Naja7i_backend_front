<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureBffRequestsAreStateful;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\NoStore;
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

        /*
         * PROXYS DE CONFIANCE — sans quoi la limitation de tentatives ne
         * protège plus rien.
         *
         * En production, l'API ne voit jamais le candidat : la requête traverse
         * Caddy puis le BFF Nitro, qui la relaie de serveur à serveur en
         * posant `X-Forwarded-For`. Tant que ce proxy n'est pas déclaré de
         * confiance, `$request->ip()` renvoie l'adresse d'un conteneur —
         * LA MÊME pour tout le monde.
         *
         * Conséquence exacte sur ce produit : `login_throttle.per_ip` autorise
         * 30 tentatives par quart d'heure (config/naja7i.php). Trente échecs
         * cumulés, d'où qu'ils viennent, verrouilleraient la connexion de la
         * plateforme entière. Et la preuve de recueil des consentements
         * enregistrerait cette même adresse pour chaque candidat.
         *
         * La valeur vient de l'environnement : en local, aucun proxy n'est de
         * confiance, ce qui est la bonne valeur par défaut. En production,
         * `TRUSTED_PROXIES=*` est acceptable parce que l'API n'a AUCUN port
         * publié — rien d'autre que le BFF ne peut l'atteindre.
         */
        $proxies = env('TRUSTED_PROXIES');
        if ($proxies !== null && $proxies !== '') {
            $middleware->trustProxies(
                at: $proxies === '*' ? '*' : array_map('trim', explode(',', (string) $proxies)),
            );
        }

        /* `permission:<code>` porte l'autorisation fine de l'ADR-0009. Elle est
         * déclarée sur la ROUTE et non dans la méthode : une action protégée
         * doit l'être avant que le contrôleur ne s'exécute, et le contrôle
         * reste lisible depuis la table des routes. */
        $middleware->alias([
            'verified.api' => EnsureEmailIsVerified::class,
            'permission' => RequirePermission::class,
            /* Une contrainte de transport se lit dans la table des routes, au
             * même titre qu'une autorisation. Voir NoStore : `seconds_remaining`
             * vient du serveur et ne survit pas à une mise en cache. */
            'no-store' => NoStore::class,
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
