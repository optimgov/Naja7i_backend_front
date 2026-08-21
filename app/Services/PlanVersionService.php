<?php

namespace App\Services;

use App\Exceptions\PaiementRefuse;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\QuotaProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Crée, sous verrou, la version correspondant à la projection courante. */
final class PlanVersionService
{
    /** @var list<string> */
    public const CONTRACTUAL_FIELDS = [
        'name_fr',
        'name_ar',
        'description_fr',
        'description_ar',
        'price_cents',
        'currency',
        'duration_days',
        'capabilities',
        /* La SÉLECTION du profil, pas ses valeurs. Changer de profil est un
         * geste commercial : il versionne. Amender le profil sélectionné n'en
         * est pas un — sinon l'admin pédagogique recomposerait, depuis son
         * registre, des offres qu'elle ne voit pas. */
        'quota_profile_id',
    ];

    /** Ce que la version COPIE du profil, en plus de la sélection elle-même. */
    private const QUOTA_SNAPSHOT = [
        'quota_profile_code',
        'quota_unit',
        'quota_periodicity',
        'quota_value',
        'quota_min_value',
        'quota_max_value',
        'quota_min_justification',
        'quota_max_justification',
    ];

    public function current(Plan $plan): PlanVersion
    {
        return DB::transaction(function () use ($plan): PlanVersion {
            /** @var Plan $locked */
            $locked = Plan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            $latest = $locked->versions()->latest('version')->first();
            $snapshot = $locked->only(self::CONTRACTUAL_FIELDS);

            if ($latest !== null && $this->sameContract($latest, $snapshot)) {
                if ($locked->current_version_id !== $latest->id) {
                    $locked->forceFill(['current_version_id' => $latest->id])->saveQuietly();
                }

                return $latest;
            }

            $version = $locked->versions()->create($snapshot + $this->instantaneDeQuota($locked) + [
                'version' => ($latest?->version ?? 0) + 1,
                'reconstructed' => false,
            ]);

            $locked->forceFill(['current_version_id' => $version->id])->saveQuietly();

            return $version;
        });
    }

    /**
     * Résout sous verrou la version réellement affichée au candidat. Les
     * anciens clients sans UUID restent acceptés pendant la transition ; un
     * UUID périmé n'est jamais remplacé silencieusement.
     */
    public function purchasable(Plan $plan, ?string $displayedVersionUuid): PlanVersion
    {
        $current = $this->current($plan);

        if ($displayedVersionUuid !== null && $current->uuid !== $displayedVersionUuid) {
            throw new PaiementRefuse('version_indisponible');
        }

        return $current;
    }

    /**
     * LE FIGEMENT. Les valeurs du profil au moment de la composition, copiées.
     *
     * `assertSelectionnable` reste le point de passage : elle vérifie ici, une
     * dernière fois avant que le contrat ne devienne immuable, que le profil
     * est encore proposé et que sa valeur tient dans ses propres bornes. Ce
     * qui est copié ensuite ne se relit plus jamais depuis `quota_profiles` —
     * c'est tout l'objet de P-Q.
     *
     * @return array<string, mixed>
     */
    private function instantaneDeQuota(Plan $plan): array
    {
        if ($plan->quota_profile_id === null) {
            return array_fill_keys(self::QUOTA_SNAPSHOT, null);
        }

        /** @var QuotaProfile $profil */
        $profil = QuotaProfile::query()->whereKey($plan->quota_profile_id)->firstOrFail();
        $capacite = $profil->capability();

        $this->assertVendue($plan, $profil, $capacite);
        app(QuotaProfileService::class)->assertSelectionnable($profil, $capacite);

        return [
            'quota_profile_code' => $profil->code,
            'quota_unit' => $profil->unit,
            'quota_periodicity' => $profil->periodicity,
            'quota_value' => $profil->value,
            'quota_min_value' => $profil->min_value,
            'quota_max_value' => $profil->max_value,
            'quota_min_justification' => $profil->min_justification,
            'quota_max_justification' => $profil->max_justification,
        ];
    }

    /**
     * Une enveloppe sans capacité vendue ne compte rien.
     *
     * Un profil « questions » sur une offre qui ne vend pas `questions.answer`
     * poserait une enveloppe que rien ne débite, et l'écran promettrait
     * quarante questions à qui n'a pas le droit d'en recevoir une seule. Le
     * refus NOMME la capacité manquante : un refus muet se lit comme une panne.
     */
    private function assertVendue(Plan $plan, QuotaProfile $profil, string $capacite): void
    {
        if (in_array($capacite, $plan->capabilities ?? [], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'quota_profile_id' => "Le profil « {$profil->code} » borne {$capacite}, "
                .'que cette offre ne vend pas : une enveloppe sans capacité ne compte rien.',
        ]);
    }

    /** @param array<string, mixed> $snapshot */
    private function sameContract(PlanVersion $version, array $snapshot): bool
    {
        foreach (self::CONTRACTUAL_FIELDS as $field) {
            $left = $version->getAttribute($field);
            $right = $snapshot[$field] ?? null;

            if ($field === 'capabilities') {
                $left = array_values($left ?? []);
                $right = array_values($right ?? []);
            }

            if ($left !== $right) {
                return false;
            }
        }

        return true;
    }
}
