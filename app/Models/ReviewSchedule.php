<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un rendez-vous de révision : une ERREUR à retravailler, pas une question.
 *
 * La clé métier est le couple (compétence, cause) — voir la migration pour la
 * raison. `last_question_id` est une trace, jamais un identifiant de rendez-vous.
 */
class ReviewSchedule extends Model
{
    use BelongsToTenant, HasPublicUuid;

    protected $fillable = [
        'user_id', 'exam_id', 'competency_node_id', 'cause',
        'last_question_id', 'palier', 'due_on', 'consecutive_sure',
        'blind_error', 'last_reviewed_at',
    ];

    protected $hidden = [
        'id', 'tenant_id', 'user_id', 'exam_id', 'competency_node_id', 'last_question_id',
    ];

    /** Un rendez-vous naît au premier palier, jamais planifié dans le vide. */
    protected $attributes = [
        'palier' => 1,
        'consecutive_sure' => 0,
        'blind_error' => false,
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'blind_error' => 'boolean',
            'last_reviewed_at' => 'datetime',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(CompetencyNode::class, 'competency_node_id');
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function lastQuestion(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'last_question_id');
    }

    /**
     * Échu à la date de journée du candidat — jamais `today()` en UTC.
     *
     * `due_on <= aujourd'hui` et non `=` : un candidat qui ne se connecte pas
     * pendant trois jours doit retrouver ses rendez-vous en retard, pas les voir
     * disparaître pour être passés.
     */
    public function scopeDue(Builder $query, ?string $timezone = null): Builder
    {
        $tz = $timezone ?? config('naja7i.timezone_candidat');

        return $query->whereDate('due_on', '<=', now($tz)->toDateString());
    }

    /**
     * L'erreur aveugle d'abord, puis le plus en retard.
     *
     * Un candidat qui s'est trompé en étant sûr de lui ne réviserait jamais ce
     * point de lui-même : c'est celui qui doit remonter.
     */
    public function scopeUrgentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('blind_error')->orderBy('due_on')->orderBy('id');
    }
}
