<?php

namespace App\Policies;

use App\Models\DifficultyLevel;
use App\Models\User;
use App\Services\PermissionResolver;

/**
 * Qui corrige l'échelle — et ce n'est pas qui la POSE.
 *
 * Poser une difficulté demande `questions.difficulty` (Q-10) ; corriger
 * l'échelle elle-même demande `questions.validate`, l'autorité pédagogique.
 * Un expert qui trouve une ancre mal formulée la signale ; il ne la réécrit
 * pas seul, sinon chaque expert finirait avec la sienne et l'échelle cesserait
 * d'être commune.
 *
 * NI CRÉATION NI SUPPRESSION : cinq crans, fermés en code.
 */
class DifficultyLevelPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->peut($user);
    }

    public function view(User $user, DifficultyLevel $level): bool
    {
        return $this->peut($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, DifficultyLevel $level): bool
    {
        return $this->peut($user);
    }

    public function delete(User $user, DifficultyLevel $level): bool
    {
        return false;
    }

    private function peut(User $user): bool
    {
        return in_array('questions.validate', $this->permissions->forUser($user), true);
    }
}
