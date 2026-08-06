<?php

use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Aucune requête métier ne s'exécute sans tenant résolu (PAS-1, ADR-0002).
        $middleware->api(append: [ResolveTenant::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
