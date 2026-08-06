<?php

namespace App\Models\Concerns;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * À appliquer sur TOUTE table de la colonne « isolée » de la matrice §1.4.
 *
 * Garanties :
 *  1. Toute lecture est filtrée sur le tenant courant (scope global).
 *  2. Toute création reçoit le tenant courant si absent.
 *  3. Sortir du scope exige un acte explicite ET journalisé :
 *     Model::acrossAllTenants('raison')->... — jamais de fuite silencieuse.
 *
 * Rappel de la règle de réponse HTTP (§1.3) : une ressource d'un autre
 * tenant répond 404, jamais 403 — le 403 confirmerait son existence.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $builder->where(
                $builder->getModel()->getTable() . '.tenant_id',
                TenantContext::id() // lève une exception si aucun tenant résolu
            );
        });

        static::creating(function (Model $model) {
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', TenantContext::id());
            }
        });
    }

    /**
     * Échappement explicite et journalisé du scope tenant.
     * Usage légitime : tâches planifiées globales, support outillé.
     */
    public static function acrossAllTenants(string $reason): Builder
    {
        Log::channel('stack')->warning('tenant_scope.bypass', [
            'model'  => static::class,
            'reason' => $reason,
        ]);

        return static::query()->withoutGlobalScope('tenant');
    }
}
