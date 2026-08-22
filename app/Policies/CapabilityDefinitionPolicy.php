<?php

namespace App\Policies;

use App\Models\CapabilityDefinition;
use App\Models\User;
use App\Services\PermissionResolver;

/**
 * Qui édite la présentation des capacités — lot 3A.6.
 *
 * NI CRÉATION NI SUPPRESSION, JAMAIS. La liste des neuf codes est fermée dans
 * `CapabilityRegistry` : une capacité n'existe que si un point du code
 * l'applique. Créer une ligne ici produirait un droit qui n'ouvre rien ; en
 * supprimer une rendrait invendable une capacité que le code applique toujours.
 * Les deux boutons n'existent donc pas — c'est le premier interdit du §3 de la
 * spécification, et il se tient à l'écran comme au serveur (la contrainte
 * `capability_definitions_code_known` refuse déjà tout code inconnu).
 *
 * Ce qui reste éditable est la PRÉSENTATION : libellés et descriptions FR/AR,
 * ordre d'affichage. C'est une donnée, au sens de l'ADR-0032.
 */
class CapabilityDefinitionPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->peut($user, 'orders.view');
    }

    public function view(User $user, CapabilityDefinition $definition): bool
    {
        return $this->peut($user, 'orders.view');
    }

    public function update(User $user, CapabilityDefinition $definition): bool
    {
        return $this->peut($user, 'orders.validate');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, CapabilityDefinition $definition): bool
    {
        return false;
    }

    private function peut(User $user, string $code): bool
    {
        return in_array($code, $this->permissions->forUser($user), true);
    }
}
