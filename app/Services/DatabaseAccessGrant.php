<?php

namespace App\Services;

use App\Contracts\AccessGrant;
use App\Models\AccessGrantRecord;
use App\Models\User;

/**
 * Implémentation par octrois explicites.
 *
 * Tant qu'aucun paiement n'existe, les octrois viennent d'une saisie manuelle
 * ou d'un organisme. Brancher CMI ajoutera une origine, pas une branche de
 * code ici.
 *
 * La capacité est vérifiée AU MOMENT DE L'USAGE, jamais mise en cache dans la
 * session : un abonnement qui expire pendant une session doit prendre effet
 * immédiatement.
 */
final class DatabaseAccessGrant implements AccessGrant
{
    public function allows(User $user, string $capability, ?string $scopeUuid = null): bool
    {
        return AccessGrantRecord::where('user_id', $user->id)
            ->where('capability', $capability)
            ->active()
            ->where(function ($q) use ($scopeUuid) {
                // Un octroi sans portée vaut partout ; un octroi ciblé ne vaut
                // que sur sa portée.
                $q->whereNull('scope_uuid');

                if ($scopeUuid !== null) {
                    $q->orWhere('scope_uuid', $scopeUuid);
                }
            })
            ->exists();
    }

    /** @return list<string> */
    public function capabilities(User $user): array
    {
        return AccessGrantRecord::where('user_id', $user->id)
            ->active()
            ->pluck('capability')
            ->unique()
            ->values()
            ->all();
    }
}
