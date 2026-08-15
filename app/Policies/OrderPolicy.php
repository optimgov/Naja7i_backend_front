<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Services\PermissionResolver;

/**
 * Qui voit et qui valide les commandes — lot ABO.
 *
 * DEUX PERMISSIONS DISTINCTES, ET C'EST TOUT L'OBJET DU LOT.
 *
 * `orders.view` consulte. `orders.validate` OUVRE UN DROIT QUI VAUT DE
 * L'ARGENT, sur un compte nommé, sans qu'aucun prestataire n'en garde trace.
 * Les confondre donnerait le pouvoir d'accorder à qui n'a qu'à lire — et ce
 * pouvoir-là doit être refusable indépendamment.
 *
 * Une commande ne se modifie ni ne s'efface : elle est honorée ou refusée, et
 * les deux gestes passent par `AbonnementService`, qui laisse une trace datée
 * et nominative.
 */
class OrderPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->peut($user, 'orders.view');
    }

    public function view(User $user, Order $order): bool
    {
        return $this->peut($user, 'orders.view');
    }

    /** Valider ou refuser — le geste qui coûte de l'argent. */
    public function validate(User $user, Order $order): bool
    {
        return $this->peut($user, 'orders.validate');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Order $order): bool
    {
        return false;
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }

    private function peut(User $user, string $code): bool
    {
        return in_array($code, $this->permissions->forUser($user), true);
    }
}
