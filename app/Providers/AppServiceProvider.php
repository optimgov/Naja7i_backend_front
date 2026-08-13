<?php

namespace App\Providers;

use App\Contracts\AccessGrant;
use App\Services\DatabaseAccessGrant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->declarerLimitesDeDebit();
    }

    /**
     * Un limiteur NOMMÉ par geste, chacun dans son propre espace de clé.
     *
     * Le nom remplace `throttle:6,1` dans `routes/api.php` : le seuil se lit
     * désormais dans `config/naja7i.php`, où l'écart entre le produit et la
     * recette tient sur une ligne, à côté de la raison de cet écart.
     *
     * NOMMER SUFFIT À SÉPARER LES COMPTEURS, et c'est la correction du défaut.
     * `ThrottleRequests::handleRequestUsingNamedLimiter` construit la clé par
     * `md5($nomDuLimiteur.$limit->key)` : le nom entre dans la clé sans qu'on
     * ait à l'y mettre. C'est la forme `throttle:6,1` qui n'avait pas de nom et
     * retombait sur `resolveRequestSignature` — `sha1(domaine|ip)` sans session,
     * `sha1(user_id)` avec — donc un seau unique pour toutes les routes.
     *
     * `->by()` RESTE INDISPENSABLE, pour une autre raison : il porte l'IDENTITÉ.
     * Sans lui, `$limit->key` est vide, la clé se réduit à `md5($nom)` et le
     * seuil devient GLOBAL — tous les candidats partageraient un compteur, et
     * six inscriptions dans le monde fermeraient la route à tous les autres.
     *
     * LE SEUIL EST LU À LA REQUÊTE, pas à l'amorçage. Un limiteur figé à
     * l'amorçage ne serait pas vérifiable : un test ne peut pas changer de
     * profil sans réenregistrer le fournisseur. Le coût est une lecture de
     * configuration — déjà en cache — par requête limitée.
     */
    private function declarerLimitesDeDebit(): void
    {
        foreach (array_keys(config('naja7i.rate_limits.limits', [])) as $nom) {
            RateLimiter::for($nom, function (Request $request) use ($nom) {
                $seuils = config("naja7i.rate_limits.limits.{$nom}");
                $profil = config('naja7i.rate_limits.profile');

                // Un profil inconnu retombe sur le produit : une faute de frappe
                // dans une variable d'environnement ne doit pas ouvrir les vannes.
                $parMinute = $seuils[$profil] ?? $seuils['production'];

                return Limit::perMinute($parMinute)
                    ->by($request->user()?->getAuthIdentifier() ?? $request->ip());
            });
        }
    }
}
