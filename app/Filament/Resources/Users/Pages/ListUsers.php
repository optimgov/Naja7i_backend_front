<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Pages\ListeAvecCreation;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\ExpliqueSonEcran;
use App\Filament\Support\GuideDEcran;

final class ListUsers extends ListeAvecCreation implements ExpliqueSonEcran
{
    public static function guideDeLEcran(): GuideDEcran
    {
        return new GuideDEcran(
            titre: __('guides.users.titre'),
            role: __('guides.users.role'),
            gestes: __('guides.users.gestes'),
            quandCEstVide: __('guides.users.vide'),
            ensuite: [
                ['libelle' => __('guides.users.ensuite_commandes'), 'url' => OrderResource::getUrl('index')],
            ],
        );
    }

    protected static string $resource = UserResource::class;
}
