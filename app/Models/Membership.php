<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Table ISOLÉE par tenant (matrice §1.4) : porte le trait BelongsToTenant.
 * C'est le seul lien user × tenant × rôle du système.
 */
class Membership extends Model
{
    use BelongsToTenant, HasPublicUuid;

    protected $fillable = ['tenant_id', 'user_id', 'role_id'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
