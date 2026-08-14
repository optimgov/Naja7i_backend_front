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
        'official_question_count',
        'official_scoring_note_fr', 'official_scoring_note_ar',
        'official_admission_threshold_note_fr', 'official_admission_threshold_note_ar',
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

    /**
     * Libellé dans la langue demandée, français en repli (DET-54).
     *
     * MÊME SIGNATURE ET MÊME REPLI que `Exam::localized` et
     * `IsCatalogueEntry::localized` — un blueprint n'est pas une entrée de
     * catalogue (ni slug, ni disponibilité), il ne peut donc pas prendre le
     * trait ; mais le contrat de lecture doit rester le même partout, sans quoi
     * l'appelant devrait savoir à quel modèle il parle.
     *
     * LE REPLI EST DÉLIBÉRÉMENT LE FRANÇAIS, et pas le vide : une citation du
     * descriptif officiel qui n'est pas encore traduite vaut mieux que rien —
     * elle est vraie, seulement pas dans la bonne langue. C'est exactement le
     * choix que faisait le produit avant DET-54, à ceci près qu'il n'avait
     * alors aucune autre issue.
     */
    public function localized(string $field, ?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();
        $suffix = $locale === 'ar' ? '_ar' : '_fr';

        return $this->getAttribute($field.$suffix) ?: $this->getAttribute($field.'_fr');
    }
}
