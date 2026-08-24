<?php

namespace App\Policies;

use App\Models\ComplaintThread;
use App\Models\User;
use App\Services\PermissionResolver;

final class ComplaintThreadPolicy
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->has($user, 'complaints.view');
    }

    public function view(User $user, ComplaintThread $thread): bool
    {
        return $this->permissions->has($user, 'complaints.view');
    }

    public function reply(User $user, ComplaintThread $thread): bool
    {
        return $this->permissions->has($user, 'complaints.reply');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ComplaintThread $thread): bool
    {
        return false;
    }

    public function delete(User $user, ComplaintThread $thread): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, ComplaintThread $thread): bool
    {
        return false;
    }

    public function restore(User $user, ComplaintThread $thread): bool
    {
        return false;
    }
}
