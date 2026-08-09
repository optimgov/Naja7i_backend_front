<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une question de la banque.
 *
 * MONOLINGUE par conception : la version française et la version arabe d'une
 * même notion sont deux contenus éditoriaux distincts, liés par
 * `sibling_group`. Le référentiel CRMEF l'impose pour l'épreuve de sciences de
 * l'éducation, où le candidat compose dans la langue de son choix.
 */
class Question extends Model
{
    use HasPublicUuid;

    /** Statuts éditoriaux, du brouillon au retrait. */
    public const STATUSES = [
        'draft', 'a_verifier', 'reviewed', 'pedagogically_validated', 'published', 'retired',
    ];

    /** Seul ce statut autorise une présentation au candidat. */
    public const PUBLISHABLE = 'published';

    protected $fillable = [
        'exam_id', 'competency_node_id', 'locale', 'sibling_group',
        'stem', 'explanation', 'difficulty', 'cognitive_level',
        'version', 'supersedes_id', 'mirror_question_id', 'remediation_id',
        'delayed_review_days', 'eligible_for_diagnostic', 'eligible_for_simulation',
        'author_id', 'reviewer_id', 'validator_id',
        'status', 'kind', 'authoring', 'published_at', 'retired_at',
    ];

    protected $hidden = [
        'id', 'exam_id', 'competency_node_id', 'supersedes_id',
        'mirror_question_id', 'remediation_id',
        'author_id', 'reviewer_id', 'validator_id',
    ];

    protected function casts(): array
    {
        return [
            'eligible_for_diagnostic' => 'boolean',
            'eligible_for_simulation' => 'boolean',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(CompetencyNode::class, 'competency_node_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('position');
    }

    public function mirror(): BelongsTo
    {
        return $this->belongsTo(self::class, 'mirror_question_id');
    }

    public function remediation(): BelongsTo
    {
        return $this->belongsTo(Remediation::class);
    }

    public function contentSources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class, 'question_sources')
            ->withPivot(['locator', 'note', 'verification'])
            ->withTimestamps();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('questions.status', self::PUBLISHABLE)
            ->whereNull('questions.retired_at')
            ->whereNotNull('questions.published_at')
            ->where('questions.published_at', '<=', now());
    }

    /** Utilisable dans un diagnostic : publiée ET explicitement éligible. */
    public function scopeForDiagnostic(Builder $query): Builder
    {
        return $query->published()->where('questions.eligible_for_diagnostic', true);
    }

    public function scopeForSimulation(Builder $query): Builder
    {
        return $query->published()->where('questions.eligible_for_simulation', true);
    }

    public function correctOption(): ?QuestionOption
    {
        return $this->options->firstWhere('is_correct', true);
    }

    public function distractors()
    {
        return $this->options->where('is_correct', false);
    }

    /** Au moins une source de contenu vérifiée ? */
    public function hasVerifiedContentSource(): bool
    {
        return $this->contentSources()
            ->wherePivot('verification', 'verified')
            ->exists();
    }
}
