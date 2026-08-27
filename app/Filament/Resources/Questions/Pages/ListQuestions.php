<?php

namespace App\Filament\Resources\Questions\Pages;

use App\Filament\Pages\Couverture;
use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\Questions\QuestionResource;
use App\Filament\Support\ExpliqueSonEcran;
use App\Filament\Support\GuideDEcran;

class ListQuestions extends ListeAvecCreation implements ExpliqueSonEcran
{
    protected static string $resource = QuestionResource::class;

    public static function guideDeLEcran(): GuideDEcran
    {
        return new GuideDEcran(
            role: __('guides.questions.role'),
            gestes: __('guides.questions.gestes'),
            quandCEstVide: __('guides.questions.vide'),
            ensuite: [
                ['libelle' => __('guides.questions.ensuite_ecrire'), 'url' => QuestionResource::getUrl('create')],
                ['libelle' => __('guides.questions.ensuite_couverture'), 'url' => Couverture::getUrl()],
            ],
        );
    }
}
