<?php

namespace App\Services;

use App\Exceptions\PaiementRefuse;
use App\Models\Plan;
use App\Models\PlanVersion;
use Illuminate\Support\Facades\DB;

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

            $version = $locked->versions()->create($snapshot + [
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
