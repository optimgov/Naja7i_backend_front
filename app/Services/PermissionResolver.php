<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Résolution des permissions dans le tenant courant.
 *
 * CONTRE-REVUE BLOC-2, défense de profondeur — le résolveur retournait toutes
 * les permissions du rôle sans filtrer `platform_only`. Le trigger interdit
 * désormais d'attacher une permission réservée à un rôle attribué hors
 * plateforme, mais une seconde barrière ne coûte rien ici : une permission
 * réservée n'est jamais accordée hors du tenant plateforme, quel que soit
 * l'état des tables.
 *
 * Deux barrières valent mieux qu'une sur un chemin d'escalade de privilèges.
 */
final class PermissionResolver
{
    /** @var array<string, list<string>> */
    private array $memo = [];

    /** @return list<string> */
    public function forUser(User $user): array
    {
        $tenantId = app(TenantContext::class)->id();
        $cle = $user->id.':'.$tenantId;

        if (isset($this->memo[$cle])) {
            return $this->memo[$cle];
        }

        $requete = Permission::query()
            ->join('permission_role', 'permission_role.permission_id', '=', 'permissions.id')
            ->join('memberships', 'memberships.role_id', '=', 'permission_role.role_id')
            ->join('roles', 'roles.id', '=', 'memberships.role_id')
            ->where('memberships.user_id', $user->id)
            ->where('memberships.tenant_id', $tenantId)
            ->where('roles.is_active', true);

        // Hors plateforme, une permission réservée n'est jamais accordée.
        if ($tenantId !== TenantContext::PLATFORM_TENANT_ID) {
            $requete->where('permissions.platform_only', false);
        }

        return $this->memo[$cle] = $requete->distinct()->pluck('permissions.code')->all();
    }

    public function has(User $user, string $code): bool
    {
        return in_array($code, $this->forUser($user), true);
    }

    /** @param  list<string>  $codes */
    public function hasAny(User $user, array $codes): bool
    {
        return array_intersect($codes, $this->forUser($user)) !== [];
    }

    /** @param  list<string>  $codes */
    public function hasAll(User $user, array $codes): bool
    {
        return array_diff($codes, $this->forUser($user)) === [];
    }

    public function describe(User $user): Collection
    {
        return Permission::whereIn('code', $this->forUser($user))
            ->get()
            ->map(fn (Permission $p) => [
                'code' => $p->code,
                'domain' => $p->domain,
                'label' => $p->localized('label'),
                'platform_only' => $p->platform_only,
            ]);
    }

    public function forget(): void
    {
        $this->memo = [];
    }
}
