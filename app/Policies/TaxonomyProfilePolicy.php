<?php

namespace App\Policies;

use App\Models\TaxonomyProfile;
use App\Models\User;
use App\Services\PermissionResolver;

/**
 * Qui nomme les niveaux — lot TAXO, même permission que l'arbre.
 *
 * Le profil et l'arbre ne se séparent pas : nommer « Domaine » et
 * « Sous-domaine » sans pouvoir créer le domaine ne mène à rien, et l'inverse
 * produit des écrans candidats qui disent « niveau 2 ».
 */
class TaxonomyProfilePolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->peut($user);
    }

    public function view(User $user, TaxonomyProfile $profile): bool
    {
        return $this->peut($user);
    }

    public function create(User $user): bool
    {
        return $this->peut($user);
    }

    public function update(User $user, TaxonomyProfile $profile): bool
    {
        return $this->peut($user);
    }

    /** Un profil dont l'arbre existe ne se supprime pas : il se corrige. */
    public function delete(User $user, TaxonomyProfile $profile): bool
    {
        return false;
    }

    private function peut(User $user): bool
    {
        return in_array('taxonomy.manage', $this->permissions->forUser($user), true);
    }
}
