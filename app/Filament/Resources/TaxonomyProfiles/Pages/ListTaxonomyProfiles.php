<?php

namespace App\Filament\Resources\TaxonomyProfiles\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\TaxonomyProfiles\TaxonomyProfileResource;

class ListTaxonomyProfiles extends ListeAvecCreation
{
    protected static string $resource = TaxonomyProfileResource::class;
}
