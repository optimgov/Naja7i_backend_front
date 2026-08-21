<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\Users\UserResource;

final class ListUsers extends ListeAvecCreation
{
    protected static string $resource = UserResource::class;
}
