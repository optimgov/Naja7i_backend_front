<?php

namespace App\Models;

use App\Enums\QuestionPreparationEventType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Journal append-only des gestes humains de la préparation Q2. */
final class QuestionPreparationEvent extends Model
{
    use HasPublicUuid;

    public $timestamps = false;

    /** Aucun formulaire ne peut fabriquer un événement d'audit. */
    protected $guarded = ['*'];

    protected $hidden = ['id', 'prepared_question_id', 'actor_id'];

    protected function casts(): array
    {
        return [
            'event_type' => QuestionPreparationEventType::class,
            'before' => 'array',
            'after' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function preparedQuestion(): BelongsTo
    {
        return $this->belongsTo(PreparedQuestion::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
