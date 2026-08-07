<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Tenancy\Exceptions\NoTenantResolvedException;

/**
 * BLOC-2 (PAS-1.1) — Le contexte n'est PLUS une propriété statique.
 *
 * Pourquoi : sous Octane, l'application reste en mémoire entre deux requêtes,
 * et un worker de queue traite des jobs successifs dans le même processus.
 * Une propriété statique survit d'un cycle à l'autre, ce qui transforme la
 * garantie « aucun tenant résolu = exception » en « réutilisation silencieuse
 * du tenant précédent ». C'est la fuite la plus dangereuse possible : elle ne
 * produit ni erreur, ni alerte, ni trace.
 *
 * Ce service est enregistré en binding SCOPED (AppServiceProvider) : le
 * conteneur le réinitialise à chaque cycle de requête et à chaque job.
 * Il ne doit jamais être injecté en singleton ni stocké statiquement.
 */
class TenantContext
{
    public const PLATFORM_TENANT_ID = 1;

    private ?Tenant $current = null;

    public function set(Tenant $tenant): void
    {
        $this->current = $tenant;
    }

    public function current(): Tenant
    {
        if ($this->current === null) {
            throw new NoTenantResolvedException(
                'Aucun tenant résolu pour ce cycle d\'exécution. '
                .'Requête HTTP : le middleware ResolveTenant est-il appliqué ? '
                .'Job de queue : le job utilise-t-il le trait InteractsWithTenant ?'
            );
        }

        return $this->current;
    }

    public function id(): int
    {
        return $this->current()->id;
    }

    public function isResolved(): bool
    {
        return $this->current !== null;
    }

    public function isPlatform(): bool
    {
        return $this->isResolved() && $this->current->id === self::PLATFORM_TENANT_ID;
    }

    public function forget(): void
    {
        $this->current = null;
    }

    /**
     * Exécute un callback sous un tenant donné, puis restaure l'état précédent
     * — y compris si le callback lève une exception. Réservé aux commandes
     * console et aux jobs ; jamais utilisé dans un contrôleur.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function runFor(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->current;
        $this->current = $tenant;

        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }
}
