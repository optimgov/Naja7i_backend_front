<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une option de réponse.
 *
 * `rationale` est obligatoire pour TOUTES les options, y compris la bonne :
 * c'est ce qui rend possible la fonction « Pourquoi pas B ? ». Sans
 * justification par option, une correction se réduit à désigner la bonne
 * réponse — exactement ce que fait n'importe quel QCM gratuit.
 *
 * `cause` n'existe que sur les distracteurs, et une contrainte l'impose.
 */
class QuestionOption extends Model
{
    use HasPublicUuid;

    /** Les huit codes de la fiche F03. */
    public const CAUSES = [
        'confusion_notions', 'lecture_enonce', 'regle_mal_appliquee', 'connaissance_absente',
        'source_perimee', 'calcul', 'piege_formulation', 'indetermine',
    ];

    protected $fillable = ['question_id', 'position', 'content', 'is_correct', 'rationale', 'cause'];

    protected $hidden = ['id', 'question_id'];

    protected function casts(): array
    {
        return ['is_correct' => 'boolean'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function isLabelled(): bool
    {
        return $this->is_correct || $this->cause !== null;
    }
}
