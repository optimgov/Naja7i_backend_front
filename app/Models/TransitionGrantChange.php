<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * L'ajustement ou la révocation d'un octroi transitoire — en ajout seul.
 *
 * L'avant et l'après de CHAQUE octroi touché : après deux ajustements
 * successifs, les échéances peuvent différer d'une capacité à l'autre, et une
 * moyenne ne se relit pas.
 */
class TransitionGrantChange extends Model
{
    use HasPublicUuid;

    public const KIND_ADJUSTED = 'adjusted';

    public const KIND_REVOKED = 'revoked';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected $hidden = ['id', 'access_grant_id', 'actor_id'];

    protected function casts(): array
    {
        return [
            'ends_at_before' => 'datetime',
            'ends_at_after' => 'datetime',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(AccessGrantRecord::class, 'access_grant_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
