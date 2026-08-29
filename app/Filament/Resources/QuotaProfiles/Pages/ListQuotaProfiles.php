<?php

namespace App\Filament\Resources\QuotaProfiles\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Resources\QuotaProfiles\QuotaProfileResource;
use App\Filament\Support\ExpliqueSonEcran;
use App\Filament\Support\GuideDEcran;

class ListQuotaProfiles extends ListeAvecCreation implements ExpliqueSonEcran
{
    public static function guideDeLEcran(): GuideDEcran
    {
        return new GuideDEcran(
            titre: __('guides.quota_profiles.titre'),
            role: __('guides.quota_profiles.role'),
            gestes: __('guides.quota_profiles.gestes'),
            quandCEstVide: __('guides.quota_profiles.vide'),
            ensuite: [
                ['libelle' => __('guides.quota_profiles.ensuite_offres'), 'url' => PlanResource::getUrl('index')],
            ],
        );
    }

    protected static string $resource = QuotaProfileResource::class;
}
