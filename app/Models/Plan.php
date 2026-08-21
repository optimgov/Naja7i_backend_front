<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Services\PlanVersionService;
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
        'code', 'name_fr', 'name_ar', 'description_fr', 'description_ar',
        'price_cents', 'currency', 'duration_days', 'capabilities',
        'quota_profile_id', 'active', 'position',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'active' => 'boolean',
            'price_cents' => 'integer',
            'duration_days' => 'integer',
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

    /** Le profil SÉLECTIONNÉ aujourd'hui — celui que la prochaine version figera. */
    public function quotaProfile(): BelongsTo
    {
        return $this->belongsTo(QuotaProfile::class);
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(PlanVersion::class, 'id', 'current_version_id');
    }

    /** Ce qui est proposé à la vente aujourd'hui. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
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
