<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une cause acquise par un candidat sur une compétence.
 *
 * L'unité de quota F03 achète un COUPLE (compétence, cause) depuis le PAS-26 —
 * la question miroir portant par construction une cause déjà payée, la faire
 * repayer vendrait deux fois le même diagnostic. Cette table matérialise cet
 * achat au lieu de le déduire : l'index unique en fait une réservation
 * atomique, ce qu'aucune jointure ne pouvait garantir (audit tournée 2).
 *
 * Elle ne se supprime jamais. Le compteur de révélations ne se remet pas à
 * zéro non plus, et pour la même raison : ce qui est payé reste acquis.
 */
class CauseAcquisition extends Model
{
    use BelongsToTenant, HasPublicUuid;

    protected $fillable = [
        'user_id', 'competency_node_id', 'cause',
        'response_id', 'granted_by_access', 'acquired_at',
    ];

    protected $hidden = ['id', 'tenant_id', 'user_id', 'competency_node_id', 'response_id'];

    protected function casts(): array
    {
        return [
            'granted_by_access' => 'boolean',
            'acquired_at' => 'datetime',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(CompetencyNode::class, 'competency_node_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
