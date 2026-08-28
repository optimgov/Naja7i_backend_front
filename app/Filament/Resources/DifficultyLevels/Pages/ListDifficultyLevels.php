<?php

namespace App\Filament\Resources\DifficultyLevels\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\DifficultyLevels\DifficultyLevelResource;
use App\Filament\Resources\Questions\QuestionResource;
use App\Filament\Support\ExpliqueSonEcran;
use App\Filament\Support\GuideDEcran;

class ListDifficultyLevels extends ListeAvecCreation implements ExpliqueSonEcran
{
    protected static string $resource = DifficultyLevelResource::class;

    public static function guideDeLEcran(): GuideDEcran
    {
        return new GuideDEcran(
            titre: __('guides.difficulty_levels.titre'),
            role: __('guides.difficulty_levels.role'),
            gestes: __('guides.difficulty_levels.gestes'),
            quandCEstVide: __('guides.difficulty_levels.vide'),
            ensuite: [
                ['libelle' => __('guides.difficulty_levels.ensuite_questions'), 'url' => QuestionResource::getUrl('index')],
            ],
        );
    }
}
