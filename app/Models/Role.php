<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Un rôle : paquet de permissions.
 *
 * `tenant_id` nul = rôle de plateforme, identique partout.
 * `tenant_id` renseigné = rôle défini par un organisme, visible de lui seul.
 *
 * Les rôles restent le moyen normal d'ATTRIBUER des permissions ; ils cessent
 * d'être le moyen de les VÉRIFIER — c'est le renversement de l'ADR-0009.
 */
class Role extends Model
{
    use HasPublicUuid;

    protected $fillable = ['tenant_id', 'code', 'label_fr', 'label_ar', 'is_staff', 'is_active'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['is_staff' => 'boolean', 'is_active' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Rôles utilisables dans un tenant : les siens et ceux de la plateforme. */
    public function scopeAvailableIn(Builder $query, int $tenantId): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId));
    }

    public function isPlatformRole(): bool
    {
        return $this->tenant_id === null;
    }
}
