<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ComplaintThreads\ComplaintThreadResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Pages\Page;
use Filament\Panel;

/** Aiguillage de la racine vers le poste de travail du rôle connecté. */
final class Accueil extends Page
{
    protected string $view = 'filament.pages.accueil';

    protected static bool $shouldRegisterNavigation = false;

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isStaff() ?? false;
    }

    public function mount(): void
    {
        $user = auth()->user();

        $destination = match (true) {
            $user?->hasRole('super_admin') => UserResource::getUrl('index'),
            $user?->hasRole('finance') => OrderResource::getUrl('index'),
            $user?->hasRole('support') => ComplaintThreadResource::getUrl('index'),
            default => Couverture::getUrl(),
        };

        $this->redirect($destination);
    }
}
