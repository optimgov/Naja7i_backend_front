<?php

namespace App\Policies;

use App\Models\CompetencyNode;
use App\Models\User;
use App\Services\PermissionResolver;

/**
 * Qui tient l'arbre — lot TAXO.
 *
 * `taxonomy.manage` existe depuis le PAS-1 et n'avait jamais eu de surface :
 * la permission était déclarée, attachée au rôle `editeur`, et aucun écran ne
 * la lisait. Un arbre se créait par migration, donc par un développeur, pour
 * chaque concours et à chaque fois.
 *
 * UN NŒUD NE SE SUPPRIME PAS depuis l'écran. Des questions et des scores de
 * maîtrise y pointent ; ce qui a été mesuré ne s'efface pas. Un nœud qui n'a
 * plus lieu d'être se DÉPLACE ou se vide de ses questions — deux gestes qui
 * laissent une trace. Le bouton n'existe donc pas, plutôt que d'échouer.
 */
class CompetencyNodePolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->peut($user);
    }

    public function view(User $user, CompetencyNode $node): bool
    {
        return $this->peut($user);
    }

    public function create(User $user): bool
    {
        return $this->peut($user);
    }

    public function update(User $user, CompetencyNode $node): bool
    {
        return $this->peut($user);
    }

    public function delete(User $user, CompetencyNode $node): bool
    {
        return false;
    }

    private function peut(User $user): bool
    {
        return in_array('taxonomy.manage', $this->permissions->forUser($user), true);
    }
}
