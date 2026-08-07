<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

/**
 * Levée lorsqu'une écriture tente de viser un tenant différent du contexte
 * courant : création avec un tenant_id étranger, modification de tenant_id,
 * ou mise à jour massive touchant cette colonne.
 */
class CrossTenantWriteException extends RuntimeException
{
    public static function forCreate(string $model, int $attempted, int $current): self
    {
        return new self(sprintf(
            'Écriture inter-tenant refusée sur %s : tenant_id=%d fourni alors que le contexte courant est %d.',
            $model, $attempted, $current
        ));
    }

    public static function forUpdate(string $model): self
    {
        return new self(sprintf(
            'Modification de tenant_id refusée sur %s : une ligne ne change jamais de tenant.',
            $model
        ));
    }

    public static function forMassUpdate(string $model): self
    {
        return new self(sprintf(
            'Mise à jour massive de tenant_id refusée sur %s.',
            $model
        ));
    }
}
