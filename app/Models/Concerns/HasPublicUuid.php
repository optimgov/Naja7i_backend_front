<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Ramsey\Uuid\Uuid;

/**
 * Convention d'identifiants (backlog NAJA7i, correction n°1) :
 *  - `id` bigint : clé interne, jointures, JAMAIS exposée par l'API.
 *  - `uuid` UUIDv7 : identifiant public, ordonné dans le temps,
 *    utilisé dans toutes les URL et payloads.
 *
 * Le route model binding résout par `uuid` par défaut.
 */
trait HasPublicUuid
{
    public static function bootHasPublicUuid(): void
    {
        static::creating(function (Model $model) {
            if ($model->getAttribute('uuid') === null) {
                $model->setAttribute('uuid', Uuid::uuid7()->toString());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function initializeHasPublicUuid(): void
    {
        // L'id interne ne sort jamais dans une sérialisation.
        $this->makeHidden(['id']);
    }
}
