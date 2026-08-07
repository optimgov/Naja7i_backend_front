<?php

namespace App\Tenancy;

use App\Models\Tenant;

/**
 * À utiliser par TOUT job de queue qui touche une table isolée.
 *
 * Un worker traite des jobs successifs dans le même processus : le job doit
 * transporter explicitement l'UUID de son tenant et le résoudre lui-même.
 * Il ne doit jamais hériter du contexte du job précédent.
 *
 * Usage :
 *   class RecalculerMaitrise implements ShouldQueue
 *   {
 *       use InteractsWithTenant;
 *
 *       public function __construct(string $tenantUuid, ...) {
 *           $this->tenantUuid = $tenantUuid;
 *       }
 *
 *       public function handle(): void {
 *           $this->withTenant(function () { ... });
 *       }
 *   }
 */
trait InteractsWithTenant
{
    public string $tenantUuid;

    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    protected function withTenant(callable $callback): mixed
    {
        $tenant = TenantBypass::run(
            'Résolution du tenant porté par un job de queue',
            fn () => Tenant::query()->where('uuid', $this->tenantUuid)->firstOrFail()
        );

        return app(TenantContext::class)->runFor($tenant, $callback);
    }
}
