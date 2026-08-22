<?php

namespace App\Filament\Resources\TaxonomyProfiles\Pages;

use App\Filament\Resources\TaxonomyProfiles\TaxonomyProfileResource;
use Filament\Resources\Pages\EditRecord;

/** Même masque, même réinjection que `EditCompetencyNode` — et même raison. */
class EditTaxonomyProfile extends EditRecord
{
    protected static string $resource = TaxonomyProfileResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['exam_id'] = $this->getRecord()->exam_id;

        return $data;
    }
}
