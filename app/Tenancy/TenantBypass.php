<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * BLOC-3 (PAS-1.1) — Point de sortie UNIQUE du scope tenant.
 *
 * Avant : `acrossAllTenants()` journalisait son propre usage, mais rien
 * n'empêchait d'appeler directement `withoutGlobalScope('tenant')`, qui ne
 * journalisait rien. La règle R10 était une convention documentée, pas une
 * règle exécutée — exactement le mode de défaillance X01–X12 du projet.
 *
 * Maintenant : le builder refuse tout retrait de scope hors de cette closure,
 * et chaque bypass produit un journal corrélable (raison, acteur, request_id).
 */
final class TenantBypass
{
    private static int $depth = 0;

    public static function isAuthorized(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Exécute un callback hors du scope tenant, avec justification obligatoire.
     *
     * @template T
     *
     * @param  non-empty-string  $reason  Raison métier, en clair. Pas « debug » ni « fix ».
     * @param  callable():T  $callback
     * @return T
     */
    public static function run(string $reason, callable $callback): mixed
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) < 10) {
            throw new \InvalidArgumentException(
                'Un bypass de scope tenant exige une raison explicite d\'au moins 10 caractères.'
            );
        }

        $correlationId = request()?->header('X-Request-Id') ?? (string) Str::uuid7();

        Log::warning('tenant_scope.bypass', [
            'reason' => $reason,
            'actor_uuid' => auth()->user()?->uuid,
            'correlation_id' => $correlationId,
            'context_tenant' => app(TenantContext::class)->isResolved()
                ? app(TenantContext::class)->id()
                : null,
            'running_in' => app()->runningInConsole() ? 'console' : 'http',
        ]);

        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }
}
