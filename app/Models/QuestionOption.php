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

    /**
     * LE NEUVIÈME CODE — DET-16, et ce n'est pas une neuvième cause.
     *
     * Les huit de F03 ne sont pas validés pédagogiquement : un expert qui ne
     * trouve pas sa case en choisit une fausse, ce qui est PIRE que de n'en
     * choisir aucune — la carte de maîtrise se met alors à mesurer un piège
     * que personne n'a tendu. Celui-ci dit « aucun des huit », et il exige son
     * texte libre : c'est ce texte qui dira, dans six mois, quels codes
     * manquaient réellement.
     */
    public const CAUSE_HORS_NOMENCLATURE = 'hors_nomenclature';

    /** @return list<string> */
    public static function causesSelectionnables(): array
    {
        return [...self::CAUSES, self::CAUSE_HORS_NOMENCLATURE];
    }

    protected $fillable = ['question_id', 'position', 'content', 'is_correct', 'rationale', 'cause', 'cause_note'];

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
