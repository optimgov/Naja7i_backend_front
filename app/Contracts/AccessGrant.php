<?php

namespace App\Contracts;

use App\Models\User;

/**
 * Contrat unique d'autorisation produit (ADR-0010).
 *
 * Une seule question, posée partout de la même façon : ce candidat a-t-il le
 * droit d'utiliser cette capacité ? Aucun contrôleur ne doit interroger un
 * abonnement, un rôle ou un niveau de compte directement.
 *
 * Le rôle dit QUI vous êtes. La capacité dit CE QUE vous avez obtenu.
 */
interface AccessGrant
{
    public const CAUSE_REVEAL = 'corrections.cause';

    public const SERIES_TARGETED = 'series.targeted';

    public const SIMULATOR_FULL = 'simulator.full';

    public const CERTIFICATION = 'certification.take';

    public function allows(User $user, string $capability, ?string $scopeUuid = null): bool;

    /** @return list<string> capacités actives, pour l'écran de compte */
    public function capabilities(User $user): array;
}
