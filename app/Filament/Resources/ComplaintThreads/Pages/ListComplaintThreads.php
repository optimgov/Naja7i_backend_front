<?php

namespace App\Filament\Resources\ComplaintThreads\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\ComplaintThreads\ComplaintThreadResource;

final class ListComplaintThreads extends ListeAvecCreation
{
    protected static string $resource = ComplaintThreadResource::class;
}
