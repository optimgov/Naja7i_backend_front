<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Résout le tenant courant AVANT toute logique métier.
 *
 * PAS-1.1 : le contexte est désormais résolu depuis le conteneur (binding
 * scoped), plus depuis une propriété statique. Le `finally` libère le contexte
 * en fin de cycle — ceinture et bretelles avec la réinitialisation du
 * conteneur, notamment sous Octane.
 *
 * Au lancement (B2C), la résolution est triviale : tout le trafic candidat
 * appartient au tenant plateforme. La résolution par organisation (sous-domaine
 * ou en-tête) arrivera au gate « premier partenaire B2B », en même temps que la
 * Row-Level Security.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::query()
            ->where('kind', 'platform')
            ->firstOrFail();

        $this->context->set($tenant);

        try {
            return $next($request);
        } finally {
            $this->context->forget();
        }
    }
}
