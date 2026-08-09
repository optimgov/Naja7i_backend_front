<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Résolution des permissions d'un utilisateur DANS LE TENANT COURANT.
 *
 * Le cumul de rôles donne l'UNION de leurs permissions — jamais
 * l'intersection : un utilisateur à la fois auteur et réviseur peut faire les
 * deux.
 *
 * Le résultat est mémoïsé pour la durée de la requête seulement. Aucune mise
 * en cache persistante : retirer une permission à un rôle doit prendre effet
 * sans redéploiement ni purge (ADR-0009).
 */
final class PermissionResolver
{
    /** @var array<string, list<string>> */
    private array $memo = [];

    /** @return list<string> */
    public function forUser(User $user): array
    {
        $cle = $user->id.':'.app(TenantContext::class)->id();

        if (isset($this->memo[$cle])) {
            return $this->memo[$cle];
        }

        $codes = Permission::query()
            ->join('permission_role', 'permission_role.permission_id', '=', 'permissions.id')
            ->join('memberships', 'memberships.role_id', '=', 'permission_role.role_id')
            ->where('memberships.user_id', $user->id)
            // Le scope tenant de memberships ne s'applique pas à une jointure
            // brute : on filtre explicitement.
            ->where('memberships.tenant_id', app(TenantContext::class)->id())
            ->distinct()
            ->pluck('permissions.code')
            ->all();

        return $this->memo[$cle] = $codes;
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

    /** Détail lisible, pour l'écran d'administration. */
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

    /** Réservé aux tests : vide la mémoïsation. */
    public function forget(): void
    {
        $this->memo = [];
    }
}
