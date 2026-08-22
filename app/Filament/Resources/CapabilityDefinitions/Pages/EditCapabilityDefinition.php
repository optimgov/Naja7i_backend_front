<?php

namespace App\Filament\Resources\CapabilityDefinitions\Pages;

use App\Filament\Resources\CapabilityDefinitions\CapabilityDefinitionResource;
use Filament\Resources\Pages\EditRecord;

class EditCapabilityDefinition extends EditRecord
{
    protected static string $resource = CapabilityDefinitionResource::class;

    /**
     * Enregistrer, c'est relire (ADR-0032).
     *
     * Le marqueur tombe ici et nulle part ailleurs : il n'est pas un champ du
     * formulaire, parce qu'un badge qu'on coche soi-même ne prouve rien. Il ne
     * se repose pas non plus — une fois qu'un humain a lu ce texte, la valeur
     * a cessé d'être celle d'un architecte.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $data + ['a_relire' => false];
    }

    /** Une capacité ne se supprime pas : le code l'applique toujours. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
