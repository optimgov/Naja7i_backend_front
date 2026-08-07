<?php

namespace App\Tenancy;

use App\Tenancy\Exceptions\CrossTenantWriteException;
use App\Tenancy\Exceptions\UnauthorizedTenantBypassException;
use Illuminate\Database\Eloquent\Builder;

/**
 * BLOC-1 et BLOC-3 (PAS-1.1).
 *
 * Les événements de modèle (`creating`, `updating`) ne sont PAS déclenchés par
 * les mises à jour massives : `Model::where(...)->update([...])` passe par le
 * builder, jamais par les hooks. Une protection posée uniquement sur les
 * événements laisse donc grande ouverte la porte du transfert de ligne :
 *
 *     Membership::where('uuid', $x)->update(['tenant_id' => $autreTenant]);
 *
 * Ce builder ferme cette porte, et interdit également de retirer le scope
 * tenant sans passer par le service TenantBypass (qui, lui, journalise).
 */
class TenantAwareBuilder extends Builder
{
    /** @var array<string, mixed> */
    public function update(array $values): int
    {
        if (array_key_exists('tenant_id', $values)) {
            throw CrossTenantWriteException::forMassUpdate($this->getModel()::class);
        }

        return parent::update($values);
    }

    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        $rows = isset($values[0]) && is_array($values[0]) ? $values : [$values];

        foreach ($rows as $row) {
            if (array_key_exists('tenant_id', $row)) {
                $this->assertTenantMatches((int) $row['tenant_id']);
            }
        }

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * Retirer le scope tenant n'est possible que depuis TenantBypass, qui pose
     * un drapeau d'autorisation le temps d'une closure. Tout autre appel — y
     * compris `withoutGlobalScopes()` — échoue bruyamment.
     */
    public function withoutGlobalScope($scope)
    {
        if ($scope === 'tenant' && ! TenantBypass::isAuthorized()) {
            throw new UnauthorizedTenantBypassException(
                'Retrait du scope tenant refusé. Utilisez TenantBypass::run($raison, $callback) : '
                .'un bypass doit être justifié et journalisé (règle R10, ADR-0002).'
            );
        }

        return parent::withoutGlobalScope($scope);
    }

    public function withoutGlobalScopes(?array $scopes = null)
    {
        $touchesTenant = $scopes === null || in_array('tenant', $scopes, true);

        if ($touchesTenant && ! TenantBypass::isAuthorized()) {
            throw new UnauthorizedTenantBypassException(
                'Retrait des scopes globaux refusé : il inclurait le scope tenant. '
                .'Utilisez TenantBypass::run($raison, $callback).'
            );
        }

        return parent::withoutGlobalScopes($scopes);
    }

    private function assertTenantMatches(int $attempted): void
    {
        $current = app(TenantContext::class)->id();

        if ($attempted !== $current) {
            throw CrossTenantWriteException::forCreate($this->getModel()::class, $attempted, $current);
        }
    }
}
