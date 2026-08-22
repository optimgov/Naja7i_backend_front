<?php

namespace App\Policies;

use App\Models\Audience;
use App\Models\User;
use App\Services\PermissionResolver;

/**
 * Qui gère les catégories de public — lot 3A.6.
 *
 * MÊME PARTAGE QUE `PlanPolicy`, et pour la même raison : `orders.view` ouvre
 * la lecture, parce que le rôle finance doit pouvoir relire le public d'une
 * offre pour comprendre une commande ; l'écriture demande `orders.validate`,
 * parce que créer une catégorie, c'est ouvrir un marché.
 *
 * UNE CATÉGORIE NE SE SUPPRIME JAMAIS : une version vendue peut la désigner.
 * Le bouton n'existe pas plutôt qu'il échoue.
 */
class AudiencePolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->peut($user, 'orders.view');
    }

    public function view(User $user, Audience $audience): bool
    {
        return $this->peut($user, 'orders.view');
    }

    public function create(User $user): bool
    {
        return $this->peut($user, 'orders.validate');
    }

    public function update(User $user, Audience $audience): bool
    {
        return $this->peut($user, 'orders.validate');
    }

    public function delete(User $user, Audience $audience): bool
    {
        return false;
    }

    private function peut(User $user, string $code): bool
    {
        return in_array($code, $this->permissions->forUser($user), true);
    }
}
