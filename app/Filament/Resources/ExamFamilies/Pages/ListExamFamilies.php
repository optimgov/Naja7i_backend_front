<?php

namespace App\Filament\Resources\ExamFamilies\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\ExamFamilies\ExamFamilyResource;
use App\Filament\Resources\Exams\ExamResource;
use App\Filament\Support\ExpliqueSonEcran;
use App\Filament\Support\GuideDEcran;

class ListExamFamilies extends ListeAvecCreation implements ExpliqueSonEcran
{
    protected static string $resource = ExamFamilyResource::class;

    public static function guideDeLEcran(): GuideDEcran
    {
        return new GuideDEcran(
            titre: __('guides.exam_families.titre'),
            role: __('guides.exam_families.role'),
            gestes: __('guides.exam_families.gestes'),
            quandCEstVide: __('guides.exam_families.vide'),
            ensuite: [
                ['libelle' => __('guides.exam_families.ensuite_epreuves'), 'url' => ExamResource::getUrl('index')],
            ],
        );
    }
}
