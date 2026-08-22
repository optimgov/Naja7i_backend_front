<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une catégorie de public : CRMEF, lycée, concours grandes écoles.
 *
 * OBJET DE CATALOGUE GLOBAL, sans `tenant_id`, comme `Plan` : ce qui est isolé
 * par organisme, c'est l'activité, jamais le référentiel.
 *
 * ELLE SERT DEUX FOIS, et c'est ce qui la rend structurante : elle nomme le
 * PUBLIC ÉLIGIBLE d'une version d'offre (Q-19, champ contractuel) et elle est
 * l'objet que désigne la portée `audience` (ADR-0031). Un droit
 * `(audience, lycee)` couvre par ascendance toute épreuve dont la famille est
 * rattachée à cette catégorie — sans quoi le type de portée ne désignerait
 * rien de parcourable (DET-87).
 *
 * ELLE NE SE SUPPRIME JAMAIS. Une version vendue peut la désigner ; on la
 * retire de la sélection. Le déclencheur `audiences_never_deleted` le tient en
 * base, la politique le dit à l'écran.
 */
class Audience extends Model
{
    use HasPublicUuid;

    protected $fillable = ['code', 'name_fr', 'name_ar', 'active', 'position'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function examFamilies(): HasMany
    {
        return $this->hasMany(ExamFamily::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    /** Ce que l'admin commerciale peut sélectionner aujourd'hui. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('code');
    }

    /** Libellé dans la langue demandée, français en repli. */
    public function localized(string $field, ?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();
        $suffix = $locale === 'ar' ? '_ar' : '_fr';

        return $this->getAttribute($field.$suffix) ?: $this->getAttribute($field.'_fr');
    }
}
