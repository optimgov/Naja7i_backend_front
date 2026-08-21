<?php

namespace App\Policies;

use App\Models\PlanVersion;
use App\Models\User;
use App\Services\PermissionResolver;

/**
 * Qui peut corriger une coquille sur une version déjà vendue — préalable P-E.
 *
 * UNE SEULE CAPACITÉ ICI, et volontairement : rien d'autre ne s'autorise sur
 * une version. Elle ne se crée pas à la main (elle est la conséquence d'une
 * composition), elle ne se supprime jamais, et elle ne se modifie que par le
 * canal éditorial. La politique n'a donc ni `create`, ni `update`, ni
 * `delete` : les absences disent la règle aussi bien que les présences, et un
 * `update` complaisant ici laisserait croire qu'un écran pourrait un jour
 * réécrire un prix.
 *
 * Le refus est un 403 explicite, pas un 404 : la règle 404 protège ce qui
 * appartient à AUTRUI contre l'énumération. Une surface d'administration
 * refusée à un membre du personnel n'est pas cachée — il sait qu'elle existe,
 * et lui répondre « introuvable » masquerait la raison sans rien protéger.
 */
class PlanVersionPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function editorialFix(User $user, PlanVersion $version): bool
    {
        return $this->permissions->has($user, 'plans.editorial_fix');
    }

    public function delete(User $user, PlanVersion $version): bool
    {
        return false;
    }
}
