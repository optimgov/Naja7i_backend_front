<?php

namespace App\Filament\Resources\DifficultyLevels\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\DifficultyLevels\DifficultyLevelResource;

class ListDifficultyLevels extends ListeAvecCreation
{
    protected static string $resource = DifficultyLevelResource::class;
}
