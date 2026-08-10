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
 * REVUE PAS-5 BLOC-1 — les champs de TRANSITION ÉDITORIALE sortent de
 * `$fillable` : `status`, `published_at`, `retired_at`, `validator_id`,
 * `reviewer_id`, `version`, `supersedes_id` et les deux drapeaux
 * d'éligibilité. Ils étaient assignables en masse, ce qui permettait de créer
 * une question directement en `published` sans qu'aucun contrôle éditorial ne
 * s'exécute.
 *
 * Ces champs se modifient désormais par `QuestionTransitionService`, chemin
 * unique et transactionnel. La même discipline que `tenant_id` sur les tables
 * isolées, pour la même raison.
 */
class Question extends Model
{
    use HasPublicUuid;

    public const STATUSES = [
        'draft', 'a_verifier', 'reviewed', 'pedagogically_validated', 'published', 'retired',
    ];

    public const PUBLISHABLE = 'published';

    /** Contenu éditorial seulement. Les transitions passent par le service. */
    protected $fillable = [
        'exam_id', 'competency_node_id', 'locale', 'sibling_group',
        'stem', 'explanation', 'difficulty', 'cognitive_level',
        'mirror_question_id', 'remediation_id', 'delayed_review_days',
        'author_id', 'kind', 'authoring',
    ];

    protected $hidden = [
        'id', 'exam_id', 'competency_node_id', 'supersedes_id',
        'mirror_question_id', 'remediation_id',
        'author_id', 'reviewer_id', 'validator_id',
    ];

    /**
     * État initial porté par le modèle, et pas seulement par la colonne.
     *
     * Les champs de transition sont hors de `$fillable` — une question ne peut
     * pas naître publiée. Mais sans défaut déclaré ici, l'instance rendue par
     * `create()` ignore le `DEFAULT 'draft'` de PostgreSQL et porte `null` :
     * `QuestionTransitionService`, qui lit `status` pour arbitrer la transition
     * suivante, n'avait alors rien à lire.
     *
     * Plus grave, `QuestionIntegrityChecker` teste `kind === 'qcm_single'` pour
     * exiger quatre options. Sur un `kind` nul, ce contrôle ne s'exécutait pas
     * du tout : une question à deux options aurait passé la publication sans
     * que rien ne le signale.
     *
     * Déclarer ces défauts ne rouvre rien : `$attributes` n'est pas un chemin
     * d'assignation de masse, seulement l'état d'un objet neuf.
     */
    protected $attributes = [
        'status' => 'draft',
        'kind' => 'qcm_single',
        'authoring' => 'human',
        'version' => 1,
        'eligible_for_diagnostic' => false,
        'eligible_for_simulation' => false,
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

    public function hasVerifiedContentSource(): bool
    {
        return $this->contentSources()->wherePivot('verification', 'verified')->exists();
    }

    public function isFrozen(): bool
    {
        return $this->status === self::PUBLISHABLE;
    }
}
