<?php

namespace App\Models;

use App\Enums\PreparedQuestionState;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une ligne de travail temporaire avant son éventuel transfert en brouillon.
 *
 * Elle ne doit être lue par aucune surface candidat. `source_facts` est gelé
 * en base et les champs de décision humaine ne sont écrits que par
 * QuestionPreparationService.
 */
final class PreparedQuestion extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'batch_id', 'import_ref', 'source_sha256', 'source_facts',
        'provisional', 'provisional_difficulty', 'proposed_answer',
        'human_fields', 'anomalies',
    ];

    protected $hidden = [
        'id', 'batch_id', 'question_id',
        'assigned_to', 'qualified_by', 'difficulty_set_by',
        'answer_confirmed_by', 'source_sha256',
    ];

    protected $attributes = [
        'state' => 'imported',
        'active' => true,
    ];

    protected function casts(): array
    {
        return [
            'source_facts' => 'array',
            'provisional' => 'array',
            'human_fields' => 'array',
            'anomalies' => 'array',
            'state' => PreparedQuestionState::class,
            'active' => 'boolean',
            'assigned_at' => 'datetime',
            'qualified_at' => 'datetime',
            'difficulty_set_at' => 'datetime',
            'answer_confirmed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(QuestionPreparationBatch::class, 'batch_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function qualifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'qualified_by');
    }

    public function difficultySetter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'difficulty_set_by');
    }

    public function answerConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answer_confirmed_by');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(CompetencyNode::class, 'competency_node_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(QuestionPreparationEvent::class)->orderBy('occurred_at');
    }
}
