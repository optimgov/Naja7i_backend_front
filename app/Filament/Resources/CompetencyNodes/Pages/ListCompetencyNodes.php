<?php

namespace App\Filament\Resources\CompetencyNodes\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\CompetencyNodes\CompetencyNodeResource;
use App\Filament\Resources\TaxonomyProfiles\TaxonomyProfileResource;
use App\Filament\Support\ExpliqueSonEcran;
use App\Filament\Support\GuideDEcran;

class ListCompetencyNodes extends ListeAvecCreation implements ExpliqueSonEcran
{
    protected static string $resource = CompetencyNodeResource::class;

    public static function guideDeLEcran(): GuideDEcran
    {
        return new GuideDEcran(
            titre: __('guides.competency_nodes.titre'),
            role: __('guides.competency_nodes.role'),
            gestes: __('guides.competency_nodes.gestes'),
            quandCEstVide: __('guides.competency_nodes.vide'),
            ensuite: [
                ['libelle' => __('guides.competency_nodes.ensuite_taxonomies'), 'url' => TaxonomyProfileResource::getUrl('index')],
            ],
        );
    }
}
