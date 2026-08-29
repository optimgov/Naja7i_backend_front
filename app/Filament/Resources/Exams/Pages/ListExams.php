<?php

namespace App\Filament\Resources\Exams\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\CompetencyNodes\CompetencyNodeResource;
use App\Filament\Resources\ExamFamilies\ExamFamilyResource;
use App\Filament\Resources\Exams\ExamResource;
use App\Filament\Support\ExpliqueSonEcran;
use App\Filament\Support\GuideDEcran;

class ListExams extends ListeAvecCreation implements ExpliqueSonEcran
{
    protected static string $resource = ExamResource::class;

    public static function guideDeLEcran(): GuideDEcran
    {
        return new GuideDEcran(
            titre: __('guides.exams.titre'),
            role: __('guides.exams.role'),
            gestes: __('guides.exams.gestes'),
            quandCEstVide: __('guides.exams.vide'),
            ensuite: [
                ['libelle' => __('guides.exams.ensuite_arbres'), 'url' => CompetencyNodeResource::getUrl('index')],
                ['libelle' => __('guides.exams.ensuite_familles'), 'url' => ExamFamilyResource::getUrl('index')],
            ],
        );
    }
}
