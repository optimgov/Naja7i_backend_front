<?php

namespace App\Models\Concerns;

use App\Tenancy\Exceptions\CrossTenantWriteException;
use App\Tenancy\TenantAwareBuilder;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * À appliquer sur TOUTE table de la colonne « isolée » de la matrice §1.4.
 *
 * PAS-1.1 — corrections BLOC-1 :
 *  - `tenant_id` ne doit PAS figurer dans $fillable du modèle : le trait
 *    l'impose lui-même. Un test architectural le vérifie.
 *  - À la création, un tenant_id étranger n'est plus « accepté parce que déjà
 *    renseigné » : il est refusé.
 *  - Une ligne ne change jamais de tenant : `updating` refuse tout
 *    isDirty('tenant_id'), et TenantAwareBuilder bloque les mises à jour
 *    massives qui contourneraient les événements de modèle.
 *
 * Rappel R5 : une ressource d'un autre tenant répond 404, jamais 403.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $builder->where(
                $builder->getModel()->getTable().'.tenant_id',
                app(TenantContext::class)->id()   // exception si non résolu
            );
        });

        static::creating(function (Model $model) {
            $current = app(TenantContext::class)->id();
            $provided = $model->getAttribute('tenant_id');

            if ($provided !== null && (int) $provided !== $current) {
                throw CrossTenantWriteException::forCreate(
                    $model::class, (int) $provided, $current
                );
            }

            $model->setAttribute('tenant_id', $current);
        });

        static::updating(function (Model $model) {
            if ($model->isDirty('tenant_id')) {
                throw CrossTenantWriteException::forUpdate($model::class);
            }
        });
    }

    /** Le trait pose lui-même tenant_id : il n'est jamais assignable en masse. */
    public function initializeBelongsToTenant(): void
    {
        $this->guarded = array_values(array_unique(
            array_merge($this->guarded === false ? [] : $this->guarded, ['tenant_id'])
        ));
    }

    /**
     * PAS-1.1 (BLOC-1, correctif d'exécution) — l'assignation de masse doit
     * REFUSER un tenant étranger, pas l'ignorer.
     *
     * `$guarded` et une liste `$fillable` sans `tenant_id` écartent la clé
     * silencieusement : `Model::create(['tenant_id' => $autre, ...])` créait
     * donc une ligne bien formée sous le tenant courant, sans la moindre
     * alerte. L'appelant croyait écrire chez le voisin, la base écrivait chez
     * lui — un écart silencieux entre l'intention et le résultat, exactement
     * ce que ce pas cherche à éliminer.
     *
     * Les hooks de modèle ne peuvent pas rattraper ce cas : à l'événement
     * `creating`, l'attribut a déjà disparu. Le refus doit donc être posé au
     * moment du remplissage, seul endroit où l'intention est encore visible.
     */
    public function fill(array $attributes)
    {
        if (array_key_exists('tenant_id', $attributes)) {
            $current = app(TenantContext::class)->id();
            $provided = (int) $attributes['tenant_id'];

            if ($provided !== $current) {
                throw CrossTenantWriteException::forCreate(static::class, $provided, $current);
            }

            // Valeur conforme au contexte : redondante, le trait la pose seul.
            unset($attributes['tenant_id']);
        }

        return parent::fill($attributes);
    }

    /** Toutes les requêtes de ce modèle passent par le builder tenant-aware. */
    public function newEloquentBuilder($query): TenantAwareBuilder
    {
        return new TenantAwareBuilder($query);
    }
}
