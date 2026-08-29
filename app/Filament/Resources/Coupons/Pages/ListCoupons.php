<?php

namespace App\Filament\Resources\Coupons\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\Coupons\CouponResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Support\ExpliqueSonEcran;
use App\Filament\Support\GuideDEcran;

class ListCoupons extends ListeAvecCreation implements ExpliqueSonEcran
{
    public static function guideDeLEcran(): GuideDEcran
    {
        return new GuideDEcran(
            titre: __('guides.coupons.titre'),
            role: __('guides.coupons.role'),
            gestes: __('guides.coupons.gestes'),
            quandCEstVide: __('guides.coupons.vide'),
            ensuite: [
                ['libelle' => __('guides.coupons.ensuite_offres'), 'url' => PlanResource::getUrl('index')],
                ['libelle' => __('guides.coupons.ensuite_commandes'), 'url' => OrderResource::getUrl('index')],
            ],
        );
    }

    protected static string $resource = CouponResource::class;
}
