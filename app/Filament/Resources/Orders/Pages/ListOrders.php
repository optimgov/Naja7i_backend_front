<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\Coupons\CouponResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Plans\PlanResource;
use App\Filament\Support\ExpliqueSonEcran;
use App\Filament\Support\GuideDEcran;

class ListOrders extends ListeAvecCreation implements ExpliqueSonEcran
{
    public static function guideDeLEcran(): GuideDEcran
    {
        return new GuideDEcran(
            titre: __('guides.orders.titre'),
            role: __('guides.orders.role'),
            gestes: __('guides.orders.gestes'),
            quandCEstVide: __('guides.orders.vide'),
            ensuite: [
                ['libelle' => __('guides.orders.ensuite_coupons'), 'url' => CouponResource::getUrl('index')],
                ['libelle' => __('guides.orders.ensuite_offres'), 'url' => PlanResource::getUrl('index')],
            ],
        );
    }

    protected static string $resource = OrderResource::class;
}
