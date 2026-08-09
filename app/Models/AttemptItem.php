<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Une question présentée dans une tentative.
 *
 * Le rattachement au nœud de compétence est COPIÉ ici et non lu depuis la
 * question : si la question était re-rattachée plus tard, l'historique de
 * maîtrise du candidat deviendrait faux rétroactivement.
 */
class AttemptItem extends Model
{
    use BelongsToTenant, HasPublicUuid;

    protected $fillable = [
        'attempt_id', 'question_id', 'competency_node_id', 'position', 'presented_at',
    ];

    protected $hidden = ['id', 'tenant_id', 'attempt_id', 'question_id', 'competency_node_id'];

    protected function casts(): array
    {
        return ['presented_at' => 'datetime'];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(CompetencyNode::class, 'competency_node_id');
    }

    public function response(): HasOne
    {
        return $this->hasOne(Response::class);
    }
}
