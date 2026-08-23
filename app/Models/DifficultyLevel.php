<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Un cran de l'échelle de difficulté — Q-09.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE CRAN EST FERMÉ EN CODE, SON TEXTE NE L'EST PAS
 *
 * Même partage que `CapabilityDefinition` : le code déclare ce qui existe, la
 * base porte ce que l'humain lit. Cinq crans, ni plus ni moins — une échelle
 * dont le nombre de crans varie ne se compare plus à elle-même d'une session à
 * l'autre, et les difficultés déjà posées perdraient leur sens. Le déclencheur
 * `difficulty_levels_fixed_scale` le tient en base.
 *
 * L'ANCRE FAIT LE TRAVAIL, PAS LE LIBELLÉ. « Transfert » se lit différemment
 * par chaque expert ; « la situation n'a pas été vue en cours » se lit pareil
 * par tous. C'est l'ancre qu'on corrige quand les difficultés déclarées
 * dérivent — et la corriger ne doit pas demander un déploiement, parce que
 * chaque formulation approximative se multiplie par 1 413.
 */
class DifficultyLevel extends Model
{
    use HasPublicUuid;

    /** Les crans, fermés en code : la base ne peut ni en ajouter ni en retirer. */
    public const CRANS = [1, 2, 3, 4, 5];

    protected $fillable = ['label_fr', 'label_ar', 'anchor_fr', 'anchor_ar'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['level' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function localized(string $field, ?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();

        return $this->getAttribute($field.($locale === 'ar' ? '_ar' : '_fr'))
            ?: $this->getAttribute($field.'_fr');
    }
}
