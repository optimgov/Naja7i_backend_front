<?php

namespace App\Filament\Resources\ComplaintThreads\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\ComplaintThreads\ComplaintThreadResource;
use App\Filament\Support\ExpliqueSonEcran;
use App\Filament\Support\GuideDEcran;

final class ListComplaintThreads extends ListeAvecCreation implements ExpliqueSonEcran
{
    public static function guideDeLEcran(): GuideDEcran
    {
        return new GuideDEcran(
            titre: __('guides.complaint_threads.titre'),
            role: __('guides.complaint_threads.role'),
            gestes: __('guides.complaint_threads.gestes'),
            quandCEstVide: __('guides.complaint_threads.vide'),
            /*
             * AUCUNE PORTE DE SORTIE, ET C'EST EXACT.
             *
             * Le rôle « support » ne porte que `complaints.view` et
             * `complaints.reply` : la fiche du candidat lui est fermée. Y
             * renvoyer aurait produit un cul-de-sac poli — un lien qui rend
             * 403 —, ce que la règle des portes interdit précisément.
             */
            ensuite: [],
        );
    }

    protected static string $resource = ComplaintThreadResource::class;
}
