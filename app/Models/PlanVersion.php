<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * L'instantané contractuel immuable d'une offre.
 *
 * Une commande lit cette ligne, jamais la projection courante dans `plans`.
 * L'immuabilité est aussi imposée en base : ni un autre service ni une console
 * d'administration ne peuvent réécrire après coup ce qui a été promis.
 */
class PlanVersion extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'plan_id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'price_cents' => 'integer',
            'duration_days' => 'integer',
            'capabilities' => 'array',
            'reconstructed' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
