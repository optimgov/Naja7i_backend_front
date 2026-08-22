<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une unité consommée — lot 3B.
 *
 * Une ligne par item SERVI, posée dans la transaction qui crée l'item. Elle ne
 * se modifie pas et ne se supprime pas : le reliquat est la somme de ces
 * lignes, et un reliquat qui remonte serait un chiffre faux.
 *
 * PAS DE `tenant_id`, comme `access_grants` : le droit appartient à la
 * personne, le débit suit le droit.
 */
class QuestionConsumption extends Model
{
    use HasPublicUuid;

    public $timestamps = false;

    protected $fillable = ['user_id', 'attempt_id', 'item_id', 'access_grant_id', 'consumed_at'];

    protected $hidden = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['consumed_at' => 'datetime'];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AttemptItem::class, 'item_id');
    }

    /** L'enveloppe débitée — nulle quand la consommation fut libre. */
    public function accessGrant(): BelongsTo
    {
        return $this->belongsTo(AccessGrantRecord::class, 'access_grant_id');
    }
}
