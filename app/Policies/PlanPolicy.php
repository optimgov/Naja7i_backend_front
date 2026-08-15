<?php

namespace App\Policies;

use App\Models\Plan;
use App\Models\User;
use App\Services\PermissionResolver;

/**
 * Qui gère les offres — lot ABO.
 *
 * `orders.view` ouvre la LECTURE : le rôle finance doit pouvoir relire un plan
 * pour comprendre une commande. L'ÉCRITURE demande `orders.validate` : changer
 * un prix ou les capacités d'une offre engage l'argent au même titre que
 * valider un coupon.
 *
 * UN PLAN NE SE SUPPRIME JAMAIS. Des commandes y pointent, et une commande dont
 * le plan a disparu ne se relit plus — `restrictOnDelete` le tient déjà en
 * base. On désactive.
 */
class PlanPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->peut($user, 'orders.view');
    }

    public function view(User $user, Plan $plan): bool
    {
        return $this->peut($user, 'orders.view');
    }

    public function create(User $user): bool
    {
        return $this->peut($user, 'orders.validate');
    }

    public function update(User $user, Plan $plan): bool
    {
        return $this->peut($user, 'orders.validate');
    }

    public function delete(User $user, Plan $plan): bool
    {
        return false;
    }

    private function peut(User $user, string $code): bool
    {
        return in_array($code, $this->permissions->forUser($user), true);
    }
}
