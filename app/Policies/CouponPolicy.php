<?php

namespace App\Policies;

use App\Models\Coupon;
use App\Models\User;
use App\Services\PermissionResolver;

/**
 * Qui engendre les coupons — lot ABO.
 *
 * ENGENDRER UN COUPON, C'EST ÉMETTRE UN TITRE. Il ne vaut rien tant qu'un
 * humain ne l'a pas honoré — c'est le second temps du moyen — mais un lot de
 * cinquante coupons est cinquante abonnements en puissance. La création
 * demande donc `orders.validate`, la même permission que la validation.
 *
 * Un coupon ne se SUPPRIME pas : il se révoque. Une commande peut y pointer, et
 * l'effacer effacerait la trace de ce qui a été donné à qui.
 */
class CouponPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->peut($user, 'orders.view');
    }

    public function view(User $user, Coupon $coupon): bool
    {
        return $this->peut($user, 'orders.view');
    }

    public function create(User $user): bool
    {
        return $this->peut($user, 'orders.validate');
    }

    public function update(User $user, Coupon $coupon): bool
    {
        return $this->peut($user, 'orders.validate');
    }

    public function delete(User $user, Coupon $coupon): bool
    {
        return false;
    }

    private function peut(User $user, string $code): bool
    {
        return in_array($code, $this->permissions->forUser($user), true);
    }
}
