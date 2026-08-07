<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    /**
     * `scoped` et non `singleton` : le conteneur réinitialise ces bindings à
     * chaque cycle de requête et à chaque job de queue. C'est précisément ce
     * qui empêche un tenant de survivre d'une exécution à la suivante (BLOC-2).
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
    }
}
