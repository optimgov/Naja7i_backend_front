<?php

namespace App\Models;

use App\Enums\QuotaPeriodicity;
use App\Enums\QuotaUnit;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * L'instantané contractuel immuable d'une offre.
 *
 * Une commande lit cette ligne, jamais la projection courante dans `plans`.
 * L'immuabilité est aussi imposée en base : ni un autre service ni une console
 * d'administration ne peuvent réécrire après coup ce qui a été promis.
 */
class PlanVersion extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $hidden = ['id', 'plan_id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'price_cents' => 'integer',
            'duration_days' => 'integer',
            'capabilities' => 'array',
            'reconstructed' => 'boolean',
            'quota_unit' => QuotaUnit::class,
            'quota_periodicity' => QuotaPeriodicity::class,
            'quota_value' => 'integer',
            'quota_min_value' => 'integer',
            'quota_max_value' => 'integer',
            'sale_opens_at' => 'datetime',
            'sale_closes_at' => 'datetime',
            'triggered_by' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** Le public éligible figé par cette version (Q-19). */
    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Le profil D'ORIGINE — trace, jamais source de lecture.
     *
     * Il dit d'où vient l'instantané ; il ne dit pas ce que la version vend.
     * Relire `quotaProfile->value` pour honorer une commande reproduirait
     * exactement le défaut que le figement ferme.
     */
    public function quotaProfile(): BelongsTo
    {
        return $this->belongsTo(QuotaProfile::class);
    }

    /** Qui a composé cette version. Nul = aucun humain n'a signé. */
    public function composedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'composed_by');
    }

    /**
     * Les droits issus de cette version, par la chaîne commande → octroi.
     *
     * `origin_reference` porte l'uuid de la commande : c'est ce qui rend la
     * chaîne relisible, et c'est par là qu'on répond à « combien de droits
     * dépendent de cette version » sans dénormaliser quoi que ce soit.
     */
    public function droitsIssus(): Builder
    {
        /* `origin_reference` est une chaîne — elle accueille aussi bien l'uuid
         * d'une commande qu'une référence de dossier support — tandis que
         * `orders.uuid` est un `uuid` PostgreSQL. La sous-requête cast donc
         * explicitement : sans cela, l'opérateur n'existe pas. */
        return AccessGrantRecord::query()
            ->whereIn('origin_reference', $this->orders()->selectRaw('uuid::text'));
    }

    /** Les coquilles corrigées sur cette version — en ajout seul. */
    public function editorialFixes(): HasMany
    {
        return $this->hasMany(PlanVersionEditorialFix::class);
    }

    /**
     * Cette version est-elle dans son calendrier de commercialisation ?
     *
     * La fenêtre est CONTRACTUELLE : c'est celle qui a été affichée, pas celle
     * que la projection porte aujourd'hui.
     */
    public function estCommercialisable(): bool
    {
        $maintenant = now();

        if ($this->sale_opens_at !== null && $this->sale_opens_at->greaterThan($maintenant)) {
            return false;
        }

        return $this->sale_closes_at === null || $this->sale_closes_at->greaterThan($maintenant);
    }

    /** Cette version pose-t-elle une enveloppe ? */
    public function poseUneEnveloppe(): bool
    {
        return $this->quota_unit !== null;
    }

    /**
     * L'enveloppe que cette version alloue à une capacité, ou rien.
     *
     * Une seule capacité est concernée : celle que l'unité compte. Les autres
     * capacités de l'offre s'ouvrent sans enveloppe — l'illimité est une
     * ABSENCE, jamais un nombre (ADR-0027).
     *
     * @return array<string, mixed>
     */
    public function enveloppePour(string $capacite): array
    {
        if (! $this->poseUneEnveloppe() || $this->quota_unit->capability() !== $capacite) {
            return [];
        }

        return [
            'quota_unit' => $this->quota_unit,
            'quota_periodicity' => $this->quota_periodicity,
            'quota_value' => $this->quota_value,
        ];
    }
}
