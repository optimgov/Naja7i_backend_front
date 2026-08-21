<?php

namespace App\Policies;

use App\Models\User;
use App\Services\PermissionResolver;

final class UserPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $actor): bool
    {
        return $this->permissions->has($actor, 'members.view');
    }

    public function view(User $actor, User $user): bool
    {
        return $this->viewAny($actor) && $user->memberships()->exists();
    }

    public function create(User $actor): bool
    {
        return $this->permissions->hasAll($actor, ['members.invite', 'roles.assign']);
    }

    public function update(User $actor, User $user): bool
    {
        return $this->permissions->hasAny($actor, ['members.invite', 'roles.assign'])
            && $user->memberships()->exists();
    }

    public function delete(User $actor, User $user): bool
    {
        return false;
    }
}
