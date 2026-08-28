<?php

namespace App\Filament\Resources\TaxonomyProfiles\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\CompetencyNodes\CompetencyNodeResource;
use App\Filament\Resources\TaxonomyProfiles\TaxonomyProfileResource;
use App\Filament\Support\ExpliqueSonEcran;
use App\Filament\Support\GuideDEcran;

class ListTaxonomyProfiles extends ListeAvecCreation implements ExpliqueSonEcran
{
    protected static string $resource = TaxonomyProfileResource::class;

    public static function guideDeLEcran(): GuideDEcran
    {
        return new GuideDEcran(
            titre: __('guides.taxonomy_profiles.titre'),
            role: __('guides.taxonomy_profiles.role'),
            gestes: __('guides.taxonomy_profiles.gestes'),
            quandCEstVide: __('guides.taxonomy_profiles.vide'),
            ensuite: [
                ['libelle' => __('guides.taxonomy_profiles.ensuite_noeuds'), 'url' => CompetencyNodeResource::getUrl('index')],
            ],
        );
    }
}
