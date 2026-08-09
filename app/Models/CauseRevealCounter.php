<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compteur cumulatif des causes d'erreur révélées à un candidat.
 * Fiche F03 : deux en compte gratuit, jamais remis à zéro.
 * Table globale : le quota suit le compte, pas le tenant.
 */
class CauseRevealCounter extends Model
{
    protected $fillable = ['user_id', 'revealed_total', 'first_revealed_at', 'last_revealed_at'];

    protected $hidden = ['id', 'user_id'];

    protected function casts(): array
    {
        return [
            'first_revealed_at' => 'datetime',
            'last_revealed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
