<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\Audiences\AudienceResource;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Resources\QuotaProfiles\QuotaProfileResource;
use App\Filament\Support\ExpliqueSonEcran;
use App\Filament\Support\GuideDEcran;

class ListPlans extends ListeAvecCreation implements ExpliqueSonEcran
{
    public static function guideDeLEcran(): GuideDEcran
    {
        return new GuideDEcran(
            titre: __('guides.plans.titre'),
            role: __('guides.plans.role'),
            gestes: __('guides.plans.gestes'),
            quandCEstVide: __('guides.plans.vide'),
            ensuite: [
                ['libelle' => __('guides.plans.ensuite_publics'), 'url' => AudienceResource::getUrl('index')],
                ['libelle' => __('guides.plans.ensuite_quotas'), 'url' => QuotaProfileResource::getUrl('index')],
            ],
        );
    }

    protected static string $resource = PlanResource::class;
}
