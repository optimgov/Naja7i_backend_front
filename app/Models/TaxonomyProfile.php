<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Déclare la forme de la taxonomie d'une famille de concours (ADR-0012).
 * Renseigné à la création du concours, dans le back-office.
 */
class TaxonomyProfile extends Model
{
    use HasPublicUuid;

    public const MAX_DEPTH = 6;

    protected $fillable = [
        'exam_family_id', 'exam_id', 'levels', 'min_depth_for_publication',
        'source_note_fr', 'source_note_ar',
    ];

    protected $hidden = ['id', 'exam_family_id', 'exam_id'];

    protected function casts(): array
    {
        return ['levels' => 'array'];
    }

    /** PAS-4.1 — Une épreuve, un profil : chacune nomme ses niveaux (ADR-0014). */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(ExamFamily::class, 'exam_family_id');
    }

    public function depth(): int
    {
        return count($this->levels ?? []);
    }

    /** Nom du niveau à une profondeur donnée, dans la langue voulue. */
    public function levelName(int $depth, ?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();
        $level = $this->levels[$depth] ?? null;

        if ($level === null) {
            return null;
        }

        return $locale === 'ar'
            ? ($level['name_ar'] ?? $level['name_fr'] ?? null)
            : ($level['name_fr'] ?? null);
    }

    /** Une question rattachée à cette profondeur est-elle publiable ? */
    public function allowsPublicationAt(int $depth): bool
    {
        return $depth >= $this->min_depth_for_publication;
    }
}
