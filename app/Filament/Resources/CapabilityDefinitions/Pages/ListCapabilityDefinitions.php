<?php

namespace App\Filament\Resources\CapabilityDefinitions\Pages;

use App\Filament\Resources\CapabilityDefinitions\CapabilityDefinitionResource;
use Filament\Resources\Pages\ListRecords;

class ListCapabilityDefinitions extends ListRecords
{
    protected static string $resource = CapabilityDefinitionResource::class;

    /** Aucune action d'en-tête : la liste des capacités est fermée en code. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
