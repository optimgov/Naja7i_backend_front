<?php

namespace App\Filament\Resources\Filieres\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\ExamFamilies\ExamFamilyResource;
use App\Filament\Resources\Filieres\FiliereResource;
use App\Filament\Support\ExpliqueSonEcran;
use App\Filament\Support\GuideDEcran;

class ListFilieres extends ListeAvecCreation implements ExpliqueSonEcran
{
    protected static string $resource = FiliereResource::class;

    public static function guideDeLEcran(): GuideDEcran
    {
        return new GuideDEcran(
            titre: __('guides.filieres.titre'),
            role: __('guides.filieres.role'),
            gestes: __('guides.filieres.gestes'),
            quandCEstVide: __('guides.filieres.vide'),
            ensuite: [
                ['libelle' => __('guides.filieres.ensuite_familles'), 'url' => ExamFamilyResource::getUrl('index')],
            ],
        );
    }
}
