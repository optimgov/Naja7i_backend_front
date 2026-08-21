<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Mutations de l'annuaire du tenant courant.
 *
 * Filament ne décide ni de la portée des rôles, ni des permissions. Cette
 * classe est le point unique auquel une autre surface devra se raccorder si
 * l'administration des personnes sort un jour du panneau.
 */
final class AccountAdministrationService
{
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly StaffInvitationService $invitations,
    ) {}

    /** @param array{email: string, phone?: string|null, locale: string, status: string, role_uuids?: list<string>} $data */
    public function create(User $actor, array $data): User
    {
        $this->authorize($actor, 'members.invite');
        $this->authorize($actor, 'roles.assign');

        $data = Validator::make($data, [
            'email' => ['required', 'email', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/', Rule::unique('users', 'phone')],
            'locale' => ['required', Rule::in(['fr', 'ar'])],
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'role_uuids' => ['required', 'array', 'min:1'],
            'role_uuids.*' => ['required', 'uuid', 'distinct'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data): User {
            $roles = $this->resolveRoles($data['role_uuids'] ?? []);

            $this->ensureRolesAssignableBy($actor, $roles);

            if ($roles->isEmpty()) {
                throw ValidationException::withMessages([
                    'role_uuids' => 'Au moins un rôle de personnel est requis.',
                ]);
            }

            if ($roles->contains(fn (Role $role): bool => ! $role->is_staff)) {
                throw ValidationException::withMessages([
                    'role_uuids' => 'Un compte candidat doit accepter lui-même les actes juridiques lors de son inscription.',
                ]);
            }

            $user = User::create(Arr::only($data, ['email', 'phone', 'locale', 'status']));

            foreach ($roles as $role) {
                Membership::create(['user_id' => $user->id, 'role_id' => $role->id]);
            }

            $this->invitations->issue($user, $actor);
            $this->permissions->forget();

            return $user->refresh();
        });
    }

    public function reinvite(User $actor, User $user): void
    {
        $this->authorize($actor, 'members.invite');
        $this->ensureMember($user);

        if ($user->password !== null || $user->identities()->where('provider', 'password')->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Ce compte possède déjà une identité par mot de passe.',
            ]);
        }

        DB::transaction(fn () => $this->invitations->issue($user, $actor));
    }

    /** @param array{email?: string|null, phone?: string|null, locale: string, status: string} $data */
    public function update(User $actor, User $user, array $data): User
    {
        $this->authorize($actor, 'members.invite');
        $this->ensureMember($user);

        $data = Validator::make($data, [
            'email' => ['nullable', 'email', 'required_without:phone', Rule::unique('users', 'email')->ignore($user)],
            'phone' => ['nullable', 'required_without:email', 'regex:/^\+[1-9][0-9]{7,14}$/', Rule::unique('users', 'phone')->ignore($user)],
            'locale' => ['required', Rule::in(['fr', 'ar'])],
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ])->validate();

        if (array_key_exists('email', $data) && $data['email'] !== $user->email) {
            $user->email_verified_at = null;
        }

        if (array_key_exists('phone', $data) && $data['phone'] !== $user->phone) {
            $user->phone_verified_at = null;
        }

        $user->fill(Arr::only($data, ['email', 'phone', 'locale', 'status']))->save();

        return $user->refresh();
    }

    /** @param list<string> $roleUuids */
    public function syncRoles(User $actor, User $user, array $roleUuids): void
    {
        $this->authorize($actor, 'roles.assign');
        $this->ensureMember($user);

        if ($actor->is($user)) {
            throw ValidationException::withMessages([
                'role_uuids' => 'Vous ne pouvez pas modifier vos propres rôles.',
            ]);
        }

        DB::transaction(function () use ($actor, $user, $roleUuids): void {
            $roles = $this->resolveRoles($roleUuids);

            if ($roles->isEmpty()) {
                throw ValidationException::withMessages([
                    'role_uuids' => 'Au moins un rôle doit rester attribué dans ce tenant.',
                ]);
            }

            $this->ensureRolesAssignableBy($actor, $roles);

            Membership::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->get();

            $existants = $user->memberships()->pluck('role_id');
            $vises = $roles->pluck('id');

            $user->memberships()->whereNotIn('role_id', $vises)->delete();

            foreach ($vises->diff($existants) as $roleId) {
                Membership::create(['user_id' => $user->id, 'role_id' => $roleId]);
            }
        });

        $this->permissions->forget();
    }

    /**
     * Rôles que l'acteur peut réellement attribuer dans le tenant courant.
     *
     * @return Collection<int, Role>
     */
    public function assignableRoles(User $actor, bool $staffOnly = false): Collection
    {
        $this->authorize($actor, 'roles.assign');

        $query = $this->availableRolesQuery()->with('permissions')->orderBy('label_fr');

        if ($staffOnly) {
            $query->where('is_staff', true);
        }

        return $query->get()
            ->filter(fn (Role $role): bool => $this->isRoleAssignableBy($actor, $role))
            ->values();
    }

    private function authorize(User $actor, string $permission): void
    {
        if (! $this->permissions->has($actor, $permission)) {
            throw new AuthorizationException("Permission requise : {$permission}.");
        }
    }

    private function ensureMember(User $user): void
    {
        if (! $user->memberships()->exists()) {
            throw new AuthorizationException('Ce compte ne fait pas partie du tenant courant.');
        }
    }

    private function ensureRolesAssignableBy(User $actor, $roles): void
    {
        foreach ($roles->loadMissing('permissions') as $role) {
            if ($role->code === 'super_admin' && ! $actor->hasRole('super_admin')) {
                throw ValidationException::withMessages([
                    'role_uuids' => 'Le rôle super_admin ne peut être attribué que par un super-administrateur.',
                ]);
            }

            if (! $this->isRoleAssignableBy($actor, $role)) {
                throw ValidationException::withMessages([
                    'role_uuids' => 'Vous ne pouvez attribuer que des rôles dont vous détenez toutes les permissions.',
                ]);
            }
        }
    }

    /** @param list<string> $uuids */
    private function resolveRoles(array $uuids)
    {
        $roles = $this->availableRolesQuery()->whereIn('uuid', $uuids)->get();

        if ($roles->count() !== count(array_unique($uuids))) {
            throw ValidationException::withMessages([
                'role_uuids' => 'Un rôle demandé est indisponible dans ce tenant.',
            ]);
        }

        return $roles;
    }

    private function availableRolesQuery()
    {
        $tenantId = app(TenantContext::class)->id();
        $query = Role::query()->availableIn($tenantId);

        if ($tenantId !== TenantContext::PLATFORM_TENANT_ID) {
            $query->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhere('is_staff', false));
        }

        return $query;
    }

    private function isRoleAssignableBy(User $actor, Role $role): bool
    {
        if ($role->code === 'super_admin' && ! $actor->hasRole('super_admin')) {
            return false;
        }

        return array_diff(
            $role->permissions->pluck('code')->all(),
            $this->permissions->forUser($actor),
        ) === [];
    }
}
