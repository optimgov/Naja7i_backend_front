<?php

namespace App\Tenancy;

use App\Models\Tenant;
use RuntimeException;

/**
 * Source unique de vérité du tenant courant pour la requête en cours.
 *
 * Aucune requête sur une table isolée ne doit s'exécuter sans tenant résolu :
 * le scope BelongsToTenant lève une exception si le contexte est vide, plutôt
 * que de retourner silencieusement les données de tous les tenants.
 */
final class TenantContext
{
    public const PLATFORM_TENANT_ID = 1;

    private static ?Tenant $current = null;

    public static function set(Tenant $tenant): void
    {
        self::$current = $tenant;
    }

    public static function current(): Tenant
    {
        if (self::$current === null) {
            throw new RuntimeException(
                'Aucun tenant résolu pour cette requête. Le middleware ResolveTenant est-il appliqué ?'
            );
        }

        return self::$current;
    }

    public static function id(): int
    {
        return self::current()->id;
    }

    public static function isResolved(): bool
    {
        return self::$current !== null;
    }

    /** Réservé aux tests et aux commandes console ciblées. */
    public static function clear(): void
    {
        self::$current = null;
    }
}
