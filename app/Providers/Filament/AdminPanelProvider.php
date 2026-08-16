<?php

namespace App\Providers\Filament;

use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Le panneau éditorial — lot A4.
 *
 * DEUX AJOUTS AU GABARIT, ET LE PREMIER N'EST PAS OPTIONNEL.
 *
 * `ResolveTenant` d'abord. Les routes de ce panneau sont des routes WEB : elles
 * ne traversent pas le groupe `api`, où ce middleware est ajouté depuis le
 * PAS-1. Sans lui, la première lecture d'une table isolée — `memberships`, que
 * `PermissionResolver` interroge à chaque autorisation — lève « aucun tenant
 * résolu » et le panneau ne s'ouvre pas du tout. Ce n'est pas une commodité :
 * c'est la condition pour que le scope d'isolation existe ici comme ailleurs.
 *
 * `SetLocale` ensuite, pour la même raison qu'il est dans le groupe `api` : il
 * lit la préférence du compte, et le back-office est bilingue comme le reste.
 *
 * L'ORDRE COMPTE. Les deux viennent APRÈS `StartSession` et `Authenticate` —
 * `SetLocale` a besoin de `$request->user()`, et l'authentification a besoin de
 * la session. Les placer en tête reviendrait à lire une préférence sur un
 * utilisateur qui n'existe pas encore.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Naja7i — rédaction')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            /*
             * LE TABLEAU DE BORD DE FILAMENT EST RETIRÉ, ET SA RACINE AVEC.
             *
             * `Couverture` sert désormais `/admin` : l'accueil d'un back-office
             * éditorial doit dire ce qu'il y a à écrire, pas afficher un
             * bandeau de bienvenue et le numéro de version de Filament. Les
             * deux ne peuvent pas coexister — `Dashboard::getRoutePath()` rend
             * « / » lui aussi, et la seconde route enregistrée l'emporterait
             * silencieusement selon l'ordre de découverte.
             */
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                /*
                 * RESOLVETENANT EN PREMIER, ET C'EST LOAD-BEARING.
                 *
                 * Il était en dernier, après `AuthenticateSession`. Or c'est
                 * `AuthenticateSession` qui résout l'utilisateur depuis le
                 * cookie « remember », et Filament appelle alors
                 * `User::canAccessPanel()`, qui interroge PermissionResolver,
                 * qui réclame le tenant. Six middlewares trop tôt : toute
                 * ouverture de /admin par un utilisateur connecté rendait une
                 * 500 `NoTenantResolvedException`.
                 *
                 * La preuve tient dans le journal de requêtes de la page
                 * d'erreur : une seule requête, `select * from users`, et
                 * AUCUNE sur `tenants` — ResolveTenant n'avait pas tourné.
                 *
                 * Contrairement à `SetLocale`, qui lit une préférence sur
                 * `$request->user()` et doit donc venir après
                 * l'authentification, ResolveTenant ne dépend de rien : il
                 * charge le tenant plateforme. Rien ne s'oppose à ce qu'il
                 * passe en tête, et tout l'exige.
                 *
                 * Pourquoi la recette ne le voyait pas : les tests du panneau
                 * posent le contexte à la main dans leur `setUp`
                 * (`app(TenantContext::class)->set(...)`). L'ordre des
                 * middlewares devient alors sans effet, et le test valide le
                 * panneau sans valider le chemin réel.
                 */
                ResolveTenant::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                SetLocale::class,
            ]);
    }
}
