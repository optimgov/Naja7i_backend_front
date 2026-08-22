<?php

namespace App\Filament\Resources\CompetencyNodes\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\CompetencyNodes\CompetencyNodeResource;

class ListCompetencyNodes extends ListeAvecCreation
{
    protected static string $resource = CompetencyNodeResource::class;
}
