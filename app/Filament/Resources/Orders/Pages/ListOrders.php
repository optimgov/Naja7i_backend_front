<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\Orders\OrderResource;

class ListOrders extends ListeAvecCreation
{
    protected static string $resource = OrderResource::class;
}
