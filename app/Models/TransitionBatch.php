<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La trace d'une pose de droit transitoire — Q-17, « tracé ».
 *
 * EN AJOUT SEUL. Un lot de distribution est une décision, et une décision qu'on
 * peut réécrire après coup ne prouve rien. Le déclencheur
 * `transition_batches_append_only` le tient en base.
 */
class TransitionBatch extends Model
{
    use HasPublicUuid;

    protected $guarded = ['*'];

    public $timestamps = false;

    protected $hidden = ['id', 'actor_id', 'plan_id', 'plan_version_id', 'audience_id'];

    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'starts_at' => 'datetime',
            'accounts_targeted' => 'integer',
            'accounts_granted' => 'integer',
            'accounts_skipped' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }
}
