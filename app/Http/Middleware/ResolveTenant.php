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
 * Au lancement (B2C uniquement), la résolution est triviale : tout le trafic
 * candidat appartient au tenant plateforme. La résolution par organisation
 * (sous-domaine ou en-tête, pour les centres partenaires) sera ajoutée au
 * moment de l'activation B2B — gate formel du plan v1.3, avec la RLS.
 *
 * Ce middleware existe dès le Pas 1 précisément pour que TOUT le code métier
 * s'écrive dès maintenant contre TenantContext, et qu'aucun refactoring ne
 * soit nécessaire quand la résolution deviendra dynamique.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::query()
            ->where('kind', 'platform')
            ->firstOrFail();

        TenantContext::set($tenant);

        return $next($request);
    }
}
