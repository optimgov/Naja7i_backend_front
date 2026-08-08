<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Matrice officielle d'une épreuve, versionnée.
 *
 * Nommé BlueprintModel et non Blueprint pour ne pas entrer en collision avec
 * Illuminate\Database\Schema\Blueprint dans les migrations.
 *
 * Les trois champs `official_*` restent nuls par défaut, et c'est délibéré :
 * les descriptifs 2025 donnent les domaines et leurs poids, jamais le nombre
 * de questions ni le barème. Un simulateur qui annoncerait « format officiel »
 * sur une base inventée serait un mensonge produit.
 */
class BlueprintModel extends Model
{
    use HasPublicUuid;

    protected $table = 'blueprints';

    protected $fillable = [
        'exam_id', 'source_id', 'version',
        'official_question_count', 'official_scoring_note_fr',
        'official_admission_threshold_note_fr',
        'coverage_note_fr', 'coverage_note_ar', 'status', 'published_at',
    ];

    protected $hidden = ['id', 'exam_id', 'source_id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** Le nombre officiel de questions est-il connu ? Presque toujours non. */
    public function hasOfficialQuestionCount(): bool
    {
        return $this->official_question_count !== null;
    }
}
