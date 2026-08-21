<?php

namespace App\Filament\Resources\QuotaProfiles\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\QuotaProfiles\QuotaProfileResource;

class ListQuotaProfiles extends ListeAvecCreation
{
    protected static string $resource = QuotaProfileResource::class;
}
