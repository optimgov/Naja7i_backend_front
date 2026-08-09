<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ressource de remédiation rattachée à un nœud de compétence.
 * Monolingue, comme les questions et pour la même raison.
 */
class Remediation extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'competency_node_id', 'locale', 'title', 'content', 'estimated_minutes', 'status',
    ];

    protected $hidden = ['id', 'competency_node_id'];

    public function node(): BelongsTo
    {
        return $this->belongsTo(CompetencyNode::class, 'competency_node_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }
}
