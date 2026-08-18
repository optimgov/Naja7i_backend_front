<?php

namespace App\Support;

use App\Services\PermissionResolver;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

/**
 * Ce qu'on peut dire à quelqu'un qu'on vient d'éconduire — D-13.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE REFUS ÉTAIT CORRECT ET MUET
 *
 * `/admin/orders` en `editorial.auteur` répondait « 403 FORBIDDEN », et rien
 * d'autre. Le code est le bon — la règle du dépôt veut un 403 EXPLICITE pour
 * une permission de personnel refusée, précisément parce que le refusé sait
 * déjà que la surface existe et qu'un 404 lui masquerait la raison sans rien
 * protéger. Mais un 403 qui ne nomme ni la permission manquante ni la surface
 * ne protège rien de plus et n'apprend rien : il laisse quelqu'un devant une
 * porte close sans lui dire quelle clé demander, ni à qui.
 *
 * C'est la règle des portes appliquée au refus : un écran qui se ferme dit ce
 * qui l'ouvrirait.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI LA PERMISSION EST DÉCLARÉE SUR LA SURFACE
 *
 * Les politiques passent toutes par `PermissionResolver`, mais elles rendent un
 * booléen : le code de la permission refusée n'existe plus au moment où
 * `abort(403)` est levé, et Filament n'en transporte aucun. On ne peut donc pas
 * le RETROUVER — il faut le DÉCLARER.
 *
 * Une déclaration à côté d'une politique est une seconde source de vérité, et
 * ce dépôt en a déjà payé le prix. Elle n'est acceptable qu'accompagnée de ce
 * qui l'empêche de dériver : `RefusNommeTest` fait entrer, sur CHAQUE surface,
 * un compte qui porte exactement la permission déclarée — il doit passer — puis
 * un compte qui porte toutes les autres — il doit être refusé. Le jour où une
 * politique change d'avis sans que la déclaration suive, ce test rougit.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class SurfaceRefusee
{
    /**
     * La surface visée par la requête, telle qu'on peut la nommer au refusé.
     *
     * @return array{
     *     chemin: string,
     *     nom: string|null,
     *     permission: string|null,
     *     permissions_du_compte: list<string>,
     *     est_du_panneau: bool,
     * }
     */
    public static function pour(Request $request): array
    {
        $classe = self::classeDeLaSurface($request);
        $user = $request->user();

        return [
            /* Le chemin SANS la requête : c'est la surface qu'on nomme, pas
             * l'appel. Un identifiant en paramètre ne se cite pas à un tiers. */
            'chemin' => '/'.ltrim($request->getPathInfo(), '/'),
            'nom' => self::nomLisible($classe),
            'permission' => $classe !== null && defined("{$classe}::PERMISSION_REQUISE")
                ? constant("{$classe}::PERMISSION_REQUISE")
                : null,
            /* CE QUE LE COMPTE PORTE, pas ce qu'il pourrait porter. Un refusé
             * qui lit ses propres permissions comprend en une seconde qu'il
             * s'est connecté avec le mauvais compte — cas le plus fréquent. */
            'permissions_du_compte' => $user !== null
                ? app(PermissionResolver::class)->forUser($user)
                : [],
            'est_du_panneau' => str_starts_with(ltrim($request->getPathInfo(), '/'), 'admin'),
        ];
    }

    /**
     * La classe Filament qui sert cette adresse, s'il y en a une.
     *
     * Filament nomme ses routes `filament.admin.resources.orders.index` et
     * `filament.admin.pages.couverture`. On remonte à la classe par le panneau
     * plutôt qu'en devinant depuis le nom : le panneau EST le registre.
     *
     * @return class-string|null
     */
    private static function classeDeLaSurface(Request $request): ?string
    {
        $nomDeRoute = $request->route()?->getName();

        if ($nomDeRoute === null || ! str_starts_with($nomDeRoute, 'filament.')) {
            return null;
        }

        $panneau = Filament::getPanel('admin', isStrict: false);

        if ($panneau === null) {
            return null;
        }

        foreach ($panneau->getResources() as $ressource) {
            foreach (array_keys($ressource::getPages()) as $page) {
                if ($ressource::getRouteBaseName($panneau).'.'.$page === $nomDeRoute) {
                    return $ressource;
                }
            }
        }

        foreach ($panneau->getPages() as $page) {
            if ($page::getRouteName($panneau) === $nomDeRoute) {
                return $page;
            }
        }

        return null;
    }

    /** @param  class-string|null  $classe */
    private static function nomLisible(?string $classe): ?string
    {
        if ($classe === null) {
            return null;
        }

        if (method_exists($classe, 'getNavigationLabel')) {
            return $classe::getNavigationLabel();
        }

        return null;
    }
}
