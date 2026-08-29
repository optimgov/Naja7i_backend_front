<?php

namespace App\Filament\Resources\CapabilityDefinitions\Pages;

use App\Filament\Resources\CapabilityDefinitions\CapabilityDefinitionResource;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Support\ExpliqueSonEcran;
use App\Filament\Support\GuideDEcran;
use Filament\Resources\Pages\ListRecords;

class ListCapabilityDefinitions extends ListRecords implements ExpliqueSonEcran
{
    public static function guideDeLEcran(): GuideDEcran
    {
        return new GuideDEcran(
            titre: __('guides.capability_definitions.titre'),
            role: __('guides.capability_definitions.role'),
            gestes: __('guides.capability_definitions.gestes'),
            quandCEstVide: __('guides.capability_definitions.vide'),
            ensuite: [
                ['libelle' => __('guides.capability_definitions.ensuite_offres'), 'url' => PlanResource::getUrl('index')],
            ],
        );
    }

    protected static string $resource = CapabilityDefinitionResource::class;

    /** Aucune action d'en-tête : la liste des capacités est fermée en code. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
