<?php

namespace App\Models;

use App\Enums\QuestionPreparationBatchStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un passage traçable d'une source dans la file de préparation.
 *
 * Ce modèle ne connaît ni publication ni transfert. Il permet seulement de
 * reprendre un travail interrompu à empreinte de source identique.
 */
final class QuestionPreparationBatch extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'source_path', 'sha256', 'counts', 'created_by', 'started_at',
    ];

    protected $hidden = [
        'id', 'created_by', 'source_path', 'sha256',
    ];

    protected $attributes = [
        'status' => 'in_progress',
    ];

    protected function casts(): array
    {
        return [
            'counts' => 'array',
            'status' => QuestionPreparationBatchStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(PreparedQuestion::class, 'batch_id');
    }
}
