<?php

namespace App\Filament\Resources\QuotaProfiles\Pages;

use App\Filament\Resources\QuotaProfiles\Concerns\RelaieLesRefusDuService;
use App\Filament\Resources\QuotaProfiles\QuotaProfileResource;
use App\Services\QuotaProfileService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * L'écriture passe par le SERVICE, jamais par Filament.
 *
 * `CreateRecord` appellerait `QuotaProfile::create($data)` — qui échouerait de
 * toute façon, puisque le modèle n'a aucun champ assignable en masse. Le
 * détour n'est donc pas une précaution de style : c'est le seul chemin qui
 * exige les justifications et qui inscrit le geste au journal avec son auteur.
 */
class CreateQuotaProfile extends CreateRecord
{
    use RelaieLesRefusDuService;

    protected static string $resource = QuotaProfileResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(QuotaProfileService::class)->definir(auth()->user(), $data);
        } catch (ValidationException $exception) {
            $this->relayerSurLeFormulaire($exception);
        }
    }

    protected function getRedirectUrl(): string
    {
        /* Vers la fiche : ce qu'on vient de borner se relit là où on le
         * modifiera, pas dans une liste qui n'en montre que le résumé. */
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
