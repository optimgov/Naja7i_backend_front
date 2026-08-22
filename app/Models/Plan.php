<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Services\PlanVersionService;
use App\Services\PorteeVendable;
use App\Services\QuotaProfileService;
use App\Support\CapabilityRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Une offre : des CAPACITÉS pendant une DURÉE, à un PRIX.
 *
 * C'est tout ce qu'un plan est, et c'est délibéré. Il ne porte aucune règle
 * métier : les capacités qu'il liste sont celles d'`AccessGrant`, et la durée
 * devient l'échéance de l'octroi. Ajouter une offre est donc une écriture en
 * base, pas un déploiement — c'est ce qui permet au back-office de les gérer.
 *
 * OBJET DE CATALOGUE COMMERCIAL, donc GLOBAL : pas de `tenant_id`. Un plan
 * s'achète par n'importe quel candidat de la plateforme. Ce qui est isolé par
 * tenant, c'est la COMMANDE — l'activité, jamais l'offre.
 */
class Plan extends Model
{
    use HasPublicUuid;

    /**
     * Les devises que le produit sait encaisser — fermées en code.
     *
     * Ce n'est pas une frilosité : une devise sans canal de paiement est une
     * promesse qu'on ne tient pas. En ajouter une est un pas de développement,
     * pas une ligne de formulaire — et la matrice §5 demande explicitement une
     * validation « devise dans la liste ».
     *
     * @var list<string>
     */
    public const DEVISES = ['MAD'];

    protected static function booted(): void
    {
        static::saving(function (Plan $plan): void {
            if (! $plan->isDirty('capabilities')) {
                return;
            }

            try {
                app(CapabilityRegistry::class)->assertCommercializable($plan->capabilities);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages([
                    'capabilities' => $exception->getMessage(),
                ]);
            }
        });

        /* LA SÉLECTION SE VÉRIFIE AVANT D'ÊTRE ÉCRITE, comme les capacités
         * juste au-dessus. La vérifier seulement à la composition laisserait
         * `plans.quota_profile_id` pointer un profil retiré, sans version pour
         * le dire — un état que rien ne rattrape ensuite. */
        static::saving(function (Plan $plan): void {
            if (! $plan->isDirty('quota_profile_id') || $plan->quota_profile_id === null) {
                return;
            }

            $profil = QuotaProfile::query()->whereKey($plan->quota_profile_id)->firstOrFail();

            app(QuotaProfileService::class)->assertSelectionnable($profil, $profil->capability());

            if (! in_array($profil->capability(), $plan->capabilities ?? [], true)) {
                throw ValidationException::withMessages([
                    'quota_profile_id' => "Le profil « {$profil->code} » borne {$profil->capability()}, "
                        .'que cette offre ne vend pas : une enveloppe sans capacité ne compte rien.',
                ]);
            }
        });

        static::saving(function (Plan $plan): void {
            if ($plan->isDirty('currency') && ! in_array($plan->currency, self::DEVISES, true)) {
                throw ValidationException::withMessages([
                    'currency' => "La devise « {$plan->currency} » n’est encaissée par aucun canal de paiement du produit.",
                ]);
            }
        });

        /* LA PORTÉE EST VÉRIFIÉE AVANT D'ÊTRE ÉCRITE. L'écran propose une liste,
         * mais une requête forgée ne passe pas par l'écran — et une portée qui
         * désigne un objet retiré ouvrirait un droit que la résolution ne sait
         * plus lire. */
        static::saving(function (Plan $plan): void {
            if ($plan->isDirty(['scope_type', 'scope_uuid'])) {
                app(PorteeVendable::class)->assertDesignable($plan->scope_type, $plan->scope_uuid);
            }
        });

        /* Le public éligible se SÉLECTIONNE parmi les catégories proposées.
         * Une catégorie retirée reste lisible sur les versions qui la portent —
         * ce qui a été vendu ne s'efface pas — mais elle ne se compose plus. */
        static::saving(function (Plan $plan): void {
            if (! $plan->isDirty('audience_id') || $plan->audience_id === null) {
                return;
            }

            $audience = Audience::query()->whereKey($plan->audience_id)->firstOrFail();

            if (! $audience->active) {
                throw ValidationException::withMessages([
                    'audience_id' => "La catégorie « {$audience->code} » n’est plus proposée à la sélection.",
                ]);
            }
        });

        static::created(function (Plan $plan): void {
            $version = app(PlanVersionService::class)->current($plan);
            $plan->setAttribute('current_version_id', $version->id);
        });

        static::updated(function (Plan $plan): void {
            if ($plan->wasChanged(PlanVersionService::CONTRACTUAL_FIELDS)) {
                $version = app(PlanVersionService::class)->current($plan);
                $plan->setAttribute('current_version_id', $version->id);
            }
        });
    }

    protected $fillable = [
        'code', 'audience_id', 'name_fr', 'name_ar', 'description_fr', 'description_ar',
        'internal_note', 'price_cents', 'currency', 'duration_days',
        'sale_opens_at', 'sale_closes_at', 'capabilities',
        'quota_profile_id', 'scope_type', 'scope_uuid', 'active', 'auto_granted', 'position',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'active' => 'boolean',
            'auto_granted' => 'boolean',
            'price_cents' => 'integer',
            'duration_days' => 'integer',
            'sale_opens_at' => 'datetime',
            'sale_closes_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class);
    }

    /** Le public éligible SÉLECTIONNÉ aujourd'hui (Q-19) — contractuel, donc versionné. */
    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    /** Le profil SÉLECTIONNÉ aujourd'hui — celui que la prochaine version figera. */
    public function quotaProfile(): BelongsTo
    {
        return $this->belongsTo(QuotaProfile::class);
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(PlanVersion::class, 'id', 'current_version_id');
    }

    /** Le porteur du gratuit — au plus un, l'index unique partiel le tient. */
    public function scopeAutoGranted(Builder $query): Builder
    {
        return $query->where('auto_granted', true);
    }

    /** Ce qui n'a pas été retiré de la vente. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Ce qui est réellement en vente MAINTENANT — actif ET dans son calendrier.
     *
     * Le calendrier ne grise pas une offre, il la retire du rendu : hors
     * période, elle n'apparaît pas au catalogue, et la souscription est refusée
     * côté serveur. Un bouton grisé forcerait le candidat à deviner pourquoi.
     */
    public function scopeEnVente(Builder $query): Builder
    {
        return $query->active()
            /* Le gratuit ne se vend pas : il se reçoit. Le laisser au catalogue
             * commercial ferait cliquer « souscrire » sur ce que le compte
             * possède déjà (ADR-0028 : rien de gratuit ne ressemble à une
             * vente). */
            ->where('auto_granted', false)
            ->where(fn (Builder $q) => $q->whereNull('sale_opens_at')->orWhere('sale_opens_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('sale_closes_at')->orWhere('sale_closes_at', '>', now()));
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('price_cents');
    }

    /** Libellé dans la langue demandée, français en repli. */
    public function localized(string $field, ?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();
        $suffix = $locale === 'ar' ? '_ar' : '_fr';

        return $this->getAttribute($field.$suffix) ?: $this->getAttribute($field.'_fr');
    }

    /** Sans terme : l'octroi qui en découle n'aura pas d'échéance. */
    public function estSansTerme(): bool
    {
        return $this->duration_days === null;
    }
}
