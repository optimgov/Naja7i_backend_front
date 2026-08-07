<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * PAS-3 : la personnalisation du lien de vérification a disparu d'ici. Le
     * PAS-2 signait une URL portant l'uuid public ; le PAS-3 y a substitué un
     * jeton opaque, parce qu'une signature calculée sur l'URL complète ne
     * survit pas au relais de Nitro (ADR-0008 §4). L'envoi passe désormais par
     * EmailVerificationService, appelé depuis le modèle User.
     */
    public function boot(): void
    {
        //
    }
}
