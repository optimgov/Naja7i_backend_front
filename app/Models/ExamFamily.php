<?php

namespace App\Models;

use App\Models\Concerns\IsCatalogueEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Une famille de concours : CRMEF, ENCG, Médecine, Agrégation…
 * C'est le niveau qui porte la taxonomie (ADR-0012).
 */
class ExamFamily extends Model
{
    use IsCatalogueEntry;

    protected $fillable = [
        'filiere_id', 'slug', 'name_fr', 'name_ar',
        'authority_fr', 'authority_ar', 'description_fr', 'description_ar',
        'position', 'status', 'availability', 'published_at',
    ];

    protected $hidden = ['id', 'filiere_id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function filiere(): BelongsTo
    {
        return $this->belongsTo(Filiere::class);
    }

    public function specialties(): HasMany
    {
        return $this->hasMany(Specialty::class);
    }

    /**
     * PAS-4.1 — Les parcours de la famille : au CRMEF, primaire bilingue,
     * primaire amazigh et secondaire. Réciproque de `Track::family()`.
     */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    /**
     * Épreuves de la famille, atteintes PAR LES PARCOURS.
     *
     * `exams` ne porte pas de `exam_family_id` : une épreuve appartient à un
     * parcours, et un parcours à une famille. Le raccourci est nécessaire pour
     * que le catalogue public expose les coefficients — sans eux, la
     * correction du PAS-4.1 reste invisible à l'écran, et la maquette laissait
     * croire que la didactique pesait un tiers alors qu'elle vaut 12 sur 40.
     */
    public function exams(): HasManyThrough
    {
        return $this->hasManyThrough(Exam::class, Track::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ExamSession::class);
    }

    public function taxonomyProfile(): HasOne
    {
        return $this->hasOne(TaxonomyProfile::class);
    }

    public function competencyNodes(): HasMany
    {
        return $this->hasMany(CompetencyNode::class);
    }
}
