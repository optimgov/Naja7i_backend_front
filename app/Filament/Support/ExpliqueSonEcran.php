<?php

namespace App\Filament\Support;

/**
 * Un écran du panneau qui sait dire à quoi il sert.
 *
 * La méthode est STATIQUE et sans dépendance : le crochet de rendu l'appelle
 * sur la page courante sans l'instancier deux fois, et un guide qui dépendrait
 * de l'état de l'écran ne serait plus un guide — ce serait un message.
 *
 * Ce que l'écran dit de son ÉTAT reste où c'est : dans `emptyStateHeading` et
 * `emptyStateDescription`, qui savent, eux, combien de lignes il y a.
 */
interface ExpliqueSonEcran
{
    public static function guideDeLEcran(): GuideDEcran;
}
