<?php

namespace App\Models;

use App\Models\Concerns\IsCatalogueEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un parcours à l'intérieur d'une famille de concours.
 * CRMEF : primaire bilingue · primaire amazigh · secondaire.
 * Chaque parcours a ses propres épreuves.
 */
class Track extends Model
{
    use IsCatalogueEntry;

    protected $fillable = [
        'exam_family_id', 'slug', 'name_fr', 'name_ar',
        'description_fr', 'description_ar',
        'position', 'status', 'availability', 'published_at',
    ];

    protected $hidden = ['id', 'exam_family_id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(ExamFamily::class, 'exam_family_id');
    }

    public function specialties(): HasMany
    {
        return $this->hasMany(Specialty::class);
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }
}
