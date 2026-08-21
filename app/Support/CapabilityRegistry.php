<?php

namespace App\Support;

use App\Contracts\AccessGrant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registre exécutable des capacités produit (ADR-0030).
 *
 * Les codes et leur caractère commercialisable restent fermés en code. La
 * base ne porte que leur présentation bilingue, éditable sans inventer une
 * nouvelle autorisation technique.
 */
final class CapabilityRegistry
{
    /** @var list<string> */
    public const ALL = [
        AccessGrant::QUESTIONS_ANSWER,
        AccessGrant::CAUSE_REVEAL,
        AccessGrant::ANNALES_PRACTICE,
        AccessGrant::SERIES_TARGETED,
        AccessGrant::SIMULATOR_FULL,
        AccessGrant::MASTERY_DETAIL,
        AccessGrant::REMEDIATION_PLAN,
        AccessGrant::MEMORY_SESSIONS,
        AccessGrant::CERTIFICATION,
    ];

    /** @var list<string> */
    public const COMMERCIALIZABLE = [
        AccessGrant::QUESTIONS_ANSWER,
        AccessGrant::CAUSE_REVEAL,
        AccessGrant::ANNALES_PRACTICE,
        AccessGrant::SERIES_TARGETED,
        AccessGrant::SIMULATOR_FULL,
        AccessGrant::MASTERY_DETAIL,
        AccessGrant::REMEDIATION_PLAN,
        AccessGrant::MEMORY_SESSIONS,
    ];

    /** @return array<string, string> */
    public function commercializableOptions(?string $locale = null): array
    {
        $locale = $locale === 'ar' ? 'ar' : 'fr';

        return DB::table('capability_definitions')
            ->whereIn('code', self::COMMERCIALIZABLE)
            ->whereNotNull('label_fr')
            ->whereNotNull('label_ar')
            ->whereNotNull('description_fr')
            ->whereNotNull('description_ar')
            ->orderBy('position')
            ->pluck("label_{$locale}", 'code')
            ->all();
    }

    /**
     * Présentation localisée d'une composition commerciale.
     *
     * Le code reste présent comme clé machine, mais n'arrive jamais seul : le
     * candidat reçoit toujours le libellé et la description à afficher.
     *
     * @param  list<string>|null  $codes
     * @return list<array{code: string, label: string, description: string}>
     */
    public function publicPresentation(?array $codes, ?string $locale = null): array
    {
        $locale = $locale === 'ar' ? 'ar' : 'fr';
        $codes = array_values(array_unique($codes ?? []));

        if ($codes === []) {
            return [];
        }

        $definitions = DB::table('capability_definitions')
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code');

        return array_map(function (string $code) use ($definitions, $locale): array {
            $definition = $definitions->get($code);

            if ($definition === null
                || blank($definition->label_fr)
                || blank($definition->label_ar)
                || blank($definition->description_fr)
                || blank($definition->description_ar)) {
                throw new RuntimeException(
                    "La capacité {$code} n'a pas de référentiel bilingue complet."
                );
            }

            return [
                'code' => $code,
                'label' => $definition->{"label_{$locale}"},
                'description' => $definition->{"description_{$locale}"},
            ];
        }, $codes);
    }

    /**
     * Garde serveur commune au back-office et à l'honneur d'une commande.
     *
     * @param  list<string>|null  $codes
     * @return list<string>
     */
    public function assertCommercializable(?array $codes): array
    {
        $codes = array_values(array_unique($codes ?? []));

        if ($codes === []) {
            throw new RuntimeException('Une offre doit composer au moins une capacité commercialisable.');
        }

        foreach ($codes as $code) {
            if (! is_string($code) || ! in_array($code, self::ALL, true)) {
                $shown = is_scalar($code) ? (string) $code : get_debug_type($code);

                throw new RuntimeException("La capacité {$shown} est inconnue.");
            }

            if (! in_array($code, self::COMMERCIALIZABLE, true)) {
                throw new RuntimeException(
                    "La capacité {$code} n'est pas commercialisable tant que sa fonction n'est pas livrée."
                );
            }
        }

        $complete = DB::table('capability_definitions')
            ->whereIn('code', $codes)
            ->whereNotNull('label_fr')
            ->whereNotNull('label_ar')
            ->whereNotNull('description_fr')
            ->whereNotNull('description_ar')
            ->pluck('code')
            ->all();

        $missing = array_values(array_diff($codes, $complete));

        if ($missing !== []) {
            throw new RuntimeException(
                'Référentiel bilingue incomplet pour : '.implode(', ', $missing).'.'
            );
        }

        return $codes;
    }
}
