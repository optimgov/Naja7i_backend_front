<?php

namespace App\Filament\Resources\Sources\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\Questions\QuestionResource;
use App\Filament\Resources\Sources\SourceResource;
use App\Filament\Support\ExpliqueSonEcran;
use App\Filament\Support\GuideDEcran;

class ListSources extends ListeAvecCreation implements ExpliqueSonEcran
{
    protected static string $resource = SourceResource::class;

    public static function guideDeLEcran(): GuideDEcran
    {
        return new GuideDEcran(
            titre: __('guides.sources.titre'),
            role: __('guides.sources.role'),
            gestes: __('guides.sources.gestes'),
            quandCEstVide: __('guides.sources.vide'),
            ensuite: [
                ['libelle' => __('guides.sources.ensuite_questions'), 'url' => QuestionResource::getUrl('index')],
            ],
        );
    }
}
