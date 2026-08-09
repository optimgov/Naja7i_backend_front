<?php

namespace App\Providers;

use App\Contracts\AccessGrant;
use App\Services\DatabaseAccessGrant;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * PAS-8 : le droit d'accès passe par un contrat, jamais par une lecture
     * directe d'un abonnement (ADR-0018 §3). L'implémentation actuelle lit la
     * table `access_grants` ; le jour où la facturation arrive, seule la classe
     * liée ici change, et aucun contrôleur ne bouge.
     */
    public function register(): void
    {
        $this->app->bind(AccessGrant::class, DatabaseAccessGrant::class);
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
