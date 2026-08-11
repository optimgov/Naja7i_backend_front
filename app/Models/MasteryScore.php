<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maîtrise d'un candidat sur un nœud de compétence.
 *
 * Le score et son volume d'évidence ne se séparent JAMAIS. Toute méthode
 * d'exposition les rend ensemble — c'est la règle R04, et la raison pour
 * laquelle il n'existe pas d'accesseur qui retourne le score seul.
 */
class MasteryScore extends Model
{
    use BelongsToTenant, HasPublicUuid;

    /** Seuils de volume d'évidence. Paramétrables, jamais devinés. */
    public const SEUIL_FAIBLE = 5;

    public const SEUIL_SUFFISANT = 10;

    protected $fillable = [
        'user_id', 'exam_id', 'competency_node_id',
        'score', 'evidence', 'answered_count', 'correct_count', 'skipped_count',
        'lucky_guess_count', 'confident_error_count',
        'last_answered_at', 'computed_at',
    ];

    protected $hidden = ['id', 'tenant_id', 'user_id', 'exam_id', 'competency_node_id'];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'last_answered_at' => 'datetime',
            'computed_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function evidenceFor(int $answered): string
    {
        return match (true) {
            $answered >= self::SEUIL_SUFFISANT => 'sufficient',
            $answered >= self::SEUIL_FAIBLE => 'low',
            default => 'insufficient',
        };
    }

    public function isDisplayable(): bool
    {
        return $this->score !== null && $this->evidence !== 'insufficient';
    }

    /** Combien de réponses manquent pour atteindre un score affichable. */
    public function answersMissing(): int
    {
        return max(0, self::SEUIL_FAIBLE - $this->answered_count);
    }

    /**
     * Représentation destinée à l'API. Le score ne sort jamais seul :
     * c'est structurel, pas une convention d'affichage.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'node_uuid' => $this->node?->uuid,
            'node_code' => $this->node?->code,
            'score' => $this->isDisplayable() ? round($this->score, 1) : null,
            'evidence' => $this->evidence,
            'answered_count' => $this->answered_count,
            'answers_missing' => $this->answersMissing(),
            /* Servies puis laissées. À ne pas confondre avec
             * `answers_missing`, qui compte ce qu'il faut RÉPONDRE ENCORE pour
             * qu'un score s'affiche — les deux sont indépendants, et un
             * candidat peut sauter la moitié d'un domaine avec un
             * `answers_missing` à zéro. */
            'skipped_count' => $this->skipped_count,
            'lucky_guesses' => $this->lucky_guess_count,
            'confident_errors' => $this->confident_error_count,
            'last_answered_at' => $this->last_answered_at?->toIso8601String(),
        ];
    }
}
