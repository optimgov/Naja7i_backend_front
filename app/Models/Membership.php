<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Table ISOLÉE par tenant. Seul lien user × tenant × rôle du système.
 *
 * PAS-1.1 : `tenant_id` a été RETIRÉ de $fillable (BLOC-1). Le trait
 * BelongsToTenant le pose lui-même et refuse toute valeur divergente.
 * Les clés étrangères internes sont masquées à la sérialisation.
 */
class Membership extends Model
{
    use BelongsToTenant, HasPublicUuid;

    protected $fillable = ['user_id', 'role_id'];

    protected $hidden = ['tenant_id', 'user_id', 'role_id'];

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
