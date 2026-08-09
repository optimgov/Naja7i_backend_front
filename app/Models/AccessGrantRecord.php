<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un octroi. Nommé AccessGrantRecord pour ne pas entrer en collision avec
 * l'interface App\Contracts\AccessGrant.
 */
class AccessGrantRecord extends Model
{
    use HasPublicUuid;

    protected $table = 'access_grants';

    protected $fillable = [
        'user_id', 'capability', 'scope_uuid', 'starts_at', 'ends_at',
        'origin', 'origin_tenant_id', 'origin_reference', 'note',
    ];

    protected $hidden = ['id', 'user_id', 'origin_tenant_id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /** Actif MAINTENANT : évalué à chaque usage, jamais mis en cache en session. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('starts_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function originTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'origin_tenant_id');
    }
}
