<?php

namespace App\Filament\Resources\Audiences\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\Audiences\AudienceResource;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Support\ExpliqueSonEcran;
use App\Filament\Support\GuideDEcran;

class ListAudiences extends ListeAvecCreation implements ExpliqueSonEcran
{
    public static function guideDeLEcran(): GuideDEcran
    {
        return new GuideDEcran(
            titre: __('guides.audiences.titre'),
            role: __('guides.audiences.role'),
            gestes: __('guides.audiences.gestes'),
            quandCEstVide: __('guides.audiences.vide'),
            ensuite: [
                ['libelle' => __('guides.audiences.ensuite_offres'), 'url' => PlanResource::getUrl('index')],
            ],
        );
    }

    protected static string $resource = AudienceResource::class;
}
