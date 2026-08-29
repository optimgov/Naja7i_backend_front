<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Traits\Tappable;

/**
 * Une épreuve du concours.
 *
 * `specialty_id` nul signifie « commune à toutes les spécialités du
 * parcours » : c'est le cas de Sciences de l'éducation, dont un seul
 * descriptif couvre les treize spécialités du secondaire.
 *
 * `coefficient` et `duration_minutes` restent nuls tant qu'une source
 * officielle ne les établit pas. Le référentiel interdit de les déduire d'une
 * autre spécialité — un candidat qui organiserait ses révisions sur un
 * coefficient inventé serait trompé sur l'essentiel.
 */
class Exam extends Model
{
    /*
     * `Tappable` : Eloquent ne fournit pas `tap()` sur les modèles, alors que
     * la construction du référentiel enchaîne `Exam::create([...])->tap(...)`
     * pour attacher le blueprint sans rompre le fil de lecture. Le trait rend
     * cette écriture possible en retournant l'épreuve elle-même.
     */
    use HasPublicUuid, Tappable;

    protected $fillable = [
        'track_id', 'specialty_id', 'code', 'name_fr', 'name_ar',
        'coefficient', 'duration_minutes', 'format', 'languages_allowed',
        'position', 'status', 'provenance', 'published_at',
        /* Faits IMPRIMÉS sur les sujets — corpus §4.2. Nuls tant qu'aucun
         * document ne les donne : ni l'un ni l'autre ne se devine. */
        'options_count', 'first_question_number',
    ];

    protected $hidden = ['id', 'track_id', 'specialty_id'];

    protected function casts(): array
    {
        return [
            'languages_allowed' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    public function blueprints(): HasMany
    {
        return $this->hasMany(BlueprintModel::class);
    }

    public function currentBlueprint(): HasOne
    {
        return $this->hasOne(BlueprintModel::class)->latestOfMany('version');
    }

    public function taxonomyProfile(): HasOne
    {
        return $this->hasOne(TaxonomyProfile::class);
    }

    /**
     * LES QUESTIONS DE L'ÉPREUVE — tous états confondus.
     *
     * Ce n'est pas ce qu'un candidat verrait : la sélection au diagnostic
     * filtre bien davantage. Cette relation sert au catalogue du back-office,
     * où le compte brut répond à une seule question — l'épreuve a-t-elle
     * quelque chose, ou est-elle une coquille. Les onze matières du lycée en
     * sont à zéro, et c'est précisément ce qu'il faut voir avant d'ouvrir leur
     * famille aux candidats.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function competencyNodes(): HasMany
    {
        return $this->hasMany(CompetencyNode::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('exams.status', 'published')
            ->whereNotNull('exams.published_at')
            ->where('exams.published_at', '<=', now());
    }

    /** Commune à toutes les spécialités du parcours ? */
    public function isShared(): bool
    {
        return $this->specialty_id === null;
    }

    public function localized(string $field, ?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();

        return $this->getAttribute($field.($locale === 'ar' ? '_ar' : '_fr'))
            ?: $this->getAttribute($field.'_fr');
    }
}
