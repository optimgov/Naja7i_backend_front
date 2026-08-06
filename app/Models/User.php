<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Compte GLOBAL : pas de tenant_id (matrice §1.4).
 * Le rattachement aux tenants passe par memberships.
 */
class User extends Authenticatable
{
    use HasPublicUuid, Notifiable;

    protected $fillable = ['email', 'phone', 'password', 'locale', 'status'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** Vérifie un rôle dans le tenant COURANT — jamais globalement. */
    public function hasRole(string $roleCode): bool
    {
        return $this->memberships() // scope tenant appliqué par BelongsToTenant
            ->whereHas('role', fn ($q) => $q->where('code', $roleCode))
            ->exists();
    }

    public function isStaff(): bool
    {
        return $this->memberships()
            ->whereHas('role', fn ($q) => $q->where('is_staff', true))
            ->exists();
    }

    /** Inscrit ce user comme candidat B2C sur le tenant plateforme. */
    public function grantCandidateRole(): Membership
    {
        return $this->memberships()->firstOrCreate([
            'tenant_id' => TenantContext::PLATFORM_TENANT_ID,
            'role_id'   => Role::where('code', 'candidat')->value('id'),
        ]);
    }
}
