<?php

namespace App\Models;

use App\Enums\QuotaPeriodicity;
use App\Enums\QuotaUnit;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un profil de quota : une VALEUR justifiée entre deux BORNES justifiées.
 *
 * Ce n'est pas un réglage commercial déguisé. C'est le seul objet du produit
 * qui autorise un nombre de questions à exister, et il porte pour cela quatre
 * choses indissociables : l'unité comptée, la valeur, la fenêtre, et les deux
 * bornes avec leur justification écrite.
 *
 * OBJET DE CATALOGUE GLOBAL, donc sans `tenant_id` — comme `Plan`. Ce qui est
 * isolé par organisme, c'est l'activité, jamais le référentiel.
 *
 * AUCUNE PORTÉE ICI (Q-20). L'enveloppe est portée par le droit et sa portée ;
 * le profil décrit ce qu'on met dedans, pas où cela s'applique.
 */
class QuotaProfile extends Model
{
    use HasPublicUuid;

    /**
     * TOUT PASSE PAR `QuotaProfileService`.
     *
     * Aucun champ n'est assignable en masse : une valeur, une borne ou une
     * justification écrite hors du service serait une modification sans
     * journal — et le journal est la seule preuve qu'une borne a été abaissée
     * avec sa raison. `$guarded = ['*']` rend le contournement visible plutôt
     * que possible.
     */
    protected $guarded = ['*'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'unit' => QuotaUnit::class,
            'periodicity' => QuotaPeriodicity::class,
            'value' => 'integer',
            'min_value' => 'integer',
            'max_value' => 'integer',
            'active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function events(): HasMany
    {
        return $this->hasMany(QuotaProfileEvent::class);
    }

    /** Ce que l'admin commerciale pourra sélectionner. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('value');
    }

    /** Libellé dans la langue demandée, français en repli. */
    public function localized(string $field, ?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();
        $suffix = $locale === 'ar' ? '_ar' : '_fr';

        return $this->getAttribute($field.$suffix) ?: $this->getAttribute($field.'_fr');
    }

    /** La valeur tient-elle dans ses propres bornes ? */
    public function contient(int $value): bool
    {
        return $value >= $this->min_value && $value <= $this->max_value;
    }

    /**
     * La capacité que ce profil peut borner, déduite de son unité.
     *
     * Un profil « questions » ne borne pas `mastery.detail` : rien n'y compte
     * de questions. Le lien est en code, jamais en base.
     */
    public function capability(): string
    {
        return $this->unit->capability();
    }
}
