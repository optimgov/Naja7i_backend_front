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

    /**
     * Le zéro initial est porté par le modèle, pas seulement par la colonne.
     *
     * `firstOrCreate(['user_id' => …])` insère la ligne sans `revealed_total` :
     * la valeur par défaut de PostgreSQL s'applique en base, mais l'instance
     * retournée ne la connaît pas et rend `null`. Un candidat qui n'a encore
     * rien révélé voyait donc son quota consommé annoncé comme indéterminé au
     * lieu de zéro, à la toute première consultation — et à elle seule.
     */
    protected $attributes = ['revealed_total' => 0];

    protected function casts(): array
    {
        return [
            'revealed_total' => 'integer',
            'first_revealed_at' => 'datetime',
            'last_revealed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
