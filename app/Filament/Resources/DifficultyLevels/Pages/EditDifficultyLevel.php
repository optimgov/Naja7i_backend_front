<?php

namespace App\Filament\Resources\DifficultyLevels\Pages;

use App\Filament\Resources\DifficultyLevels\DifficultyLevelResource;
use Filament\Resources\Pages\EditRecord;

class EditDifficultyLevel extends EditRecord
{
    protected static string $resource = DifficultyLevelResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['level'] = $this->getRecord()->level;

        return $data;
    }
}
