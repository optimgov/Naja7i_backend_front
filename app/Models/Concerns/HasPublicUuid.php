<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Convention d'identifiants (ADR-0002) :
 *  - `id` bigint : clé interne, jointures, JAMAIS exposée par l'API.
 *  - `uuid` UUIDv7 : identifiant public, ordonné dans le temps.
 *
 * PAS-1.1 (P6) : on utilise Str::uuid7() de Laravel plutôt que Ramsey en
 * direct — la dépendance n'était pas déclarée dans composer.json et le
 * projet s'appuyait sur une dépendance transitive.
 *
 * ATTENTION : makeHidden('id') ne protège QUE la sérialisation standard du
 * modèle. Il ne protège ni les tableaux construits à la main, ni le query
 * builder, ni les clés étrangères d'une relation sérialisée. La garantie
 * réelle vient des API Resources en liste blanche + du test contractuel
 * récursif (PAS-2).
 */
trait HasPublicUuid
{
    public static function bootHasPublicUuid(): void
    {
        static::creating(function (Model $model) {
            if ($model->getAttribute('uuid') === null) {
                $model->setAttribute('uuid', (string) Str::uuid7());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function initializeHasPublicUuid(): void
    {
        $this->makeHidden(['id']);
    }
}
