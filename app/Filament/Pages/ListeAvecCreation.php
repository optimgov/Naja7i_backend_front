<?php

namespace App\Filament\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Une liste dont le bouton « créer » ne peut pas être oublié.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI UNE CLASSE DE BASE PLUTÔT QUE DEUX CORRECTIFS
 *
 * `/admin/plans` et `/admin/coupons` n'avaient AUCUN bouton de création : leurs
 * pages de liste ne déclaraient pas `getHeaderActions()`, là où celles des
 * questions et des sources le faisaient. Les ressources déclaraient pourtant
 * une page `create` — la route existait, elle n'était atteignable par aucun
 * chemin de l'interface.
 *
 * Le pilote n'a donc pas pu créer une offre. Le lot ABO livrait un chemin de
 * revenu dont le premier maillon était invisible.
 *
 * On pouvait ajouter deux méthodes et clore. Ce serait recommencer au prochain
 * `ListRecords` : rien, dans la forme du code, n'aurait empêché l'oubli — et
 * l'oubli ne se voit pas, puisqu'une page sans bouton s'affiche parfaitement.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA RÈGLE EST DÉDUITE, PAS RECOPIÉE
 *
 * Le bouton apparaît si, et seulement si, la ressource déclare une page
 * `create`. C'est la ressource qui décide, et elle le décide DÉJÀ en déclarant
 * ses pages : la liste n'a plus rien à savoir.
 *
 * `OrderResource` ne déclare pas de page `create`, et c'est juste — une
 * commande naît d'un candidat qui saisit un coupon, jamais d'un membre du
 * personnel. Sa liste hérite donc de cette classe sans obtenir de bouton, sans
 * qu'aucune exception soit écrite nulle part.
 *
 * Une sous-classe qui aurait besoin d'autres actions d'en-tête les ajoute :
 * `array_merge(parent::getHeaderActions(), [...])`.
 */
abstract class ListeAvecCreation extends ListRecords
{
    protected function getHeaderActions(): array
    {
        /* `hasPage()` interroge la ressource elle-même : aucune liste à tenir
         * ici, donc aucune liste à oublier de mettre à jour. */
        if (! static::getResource()::hasPage('create')) {
            return [];
        }

        return [CreateAction::make()];
    }
}
