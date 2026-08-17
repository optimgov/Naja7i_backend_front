<?php

namespace App\Filament\Resources\Coupons\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\Coupons\CouponResource;

class ListCoupons extends ListeAvecCreation
{
    protected static string $resource = CouponResource::class;
}
