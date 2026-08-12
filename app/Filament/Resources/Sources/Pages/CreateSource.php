<?php

namespace App\Filament\Resources\Sources\Pages;

use App\Filament\Resources\Sources\SourceResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Créer une source ne demande pas de détour, et il faut dire pourquoi.
 *
 * `CreateQuestion` détourne son enregistrement vers un service parce que
 * `QuestionAuthoringService` fait trois choses qu'un `create()` ne fait pas.
 * Ici, il n'y a rien de tel : une source naît NON VÉRIFIÉE, et c'est la
 * conséquence mécanique de `verified_at` hors de `$fillable` — pas une règle
 * qu'un service devrait appliquer. Inventer un `SourceAuthoringService` pour
 * respecter une symétrie donnerait une garantie plus faible que celle-là, en
 * plus d'une classe vide.
 */
class CreateSource extends CreateRecord
{
    protected static string $resource = SourceResource::class;

    protected function getRedirectUrl(): string
    {
        /* Vers la fiche : l'encart d'état y annonce d'emblée que la source
         * n'est pas vérifiée, ce qui est l'action suivante. */
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
