<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Comportement commun aux objets de catalogue.
 *
 * Ces objets sont GLOBAUX : ils ne portent jamais `tenant_id` et n'utilisent
 * jamais BelongsToTenant (ADR-0002, ADR-0013). Un test d'architecture le
 * vérifie — c'est la règle la plus facile à enfreindre par distraction, et la
 * plus coûteuse à réparer une fois des données en place.
 */
trait IsCatalogueEntry
{
    use HasPublicUuid;

    /** Le référencement passe par le slug, pas par l'uuid. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Ne retourne que ce qui est réellement publié — jamais les brouillons. */
    public function scopePublished(Builder $query): Builder
    {
        $t = $this->getTable();

        return $query->where("{$t}.status", 'published')
            ->whereNotNull("{$t}.published_at")
            ->where("{$t}.published_at", '<=', now());
    }

    public function scopeOrdered(Builder $query): Builder
    {
        $t = $this->getTable();

        return $query->orderBy("{$t}.position")->orderBy("{$t}.name_fr");
    }

    /** Libellé dans la langue demandée, français en repli. */
    public function localized(string $field, ?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();
        $suffix = $locale === 'ar' ? '_ar' : '_fr';

        return $this->getAttribute($field.$suffix) ?: $this->getAttribute($field.'_fr');
    }

    public function isOpen(): bool
    {
        return $this->availability === 'open';
    }
}
