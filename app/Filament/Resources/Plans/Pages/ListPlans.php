<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\Plans\PlanResource;

class ListPlans extends ListeAvecCreation
{
    protected static string $resource = PlanResource::class;
}
