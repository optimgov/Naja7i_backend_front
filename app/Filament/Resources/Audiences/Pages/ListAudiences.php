<?php

namespace App\Filament\Resources\Audiences\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\Audiences\AudienceResource;

class ListAudiences extends ListeAvecCreation
{
    protected static string $resource = AudienceResource::class;
}
