<?php

namespace App\Filament\Resources\QuotaProfiles\Pages;

use App\Filament\Resources\QuotaProfiles\Concerns\RelaieLesRefusDuService;
use App\Filament\Resources\QuotaProfiles\QuotaProfileResource;
use App\Services\QuotaProfileService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Amender un profil, sous la même garde que partout ailleurs.
 *
 * `code`, `unit` et `periodicity` sont désactivés au formulaire ET refusés par
 * le service : le premier retire la tentation, le second tient la garantie
 * quand la requête ne vient pas du formulaire.
 */
class EditQuotaProfile extends EditRecord
{
    use RelaieLesRefusDuService;

    protected static string $resource = QuotaProfileResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /* Les champs figés sont désactivés à l'écran : Filament ne les renvoie
         * pas, et les faire suivre ferait échouer une amende qui ne les touche
         * pas. Le service les refuse toujours s'ils arrivent autrement. */
        unset($data['code'], $data['unit'], $data['periodicity']);

        try {
            return app(QuotaProfileService::class)->amender($record, auth()->user(), $data);
        } catch (ValidationException $exception) {
            $this->relayerSurLeFormulaire($exception);
        }
    }

    protected function getRedirectUrl(): ?string
    {
        return null;
    }
}
