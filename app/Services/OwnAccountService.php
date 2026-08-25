<?php

namespace App\Services;

use App\Models\User;
use App\Validation\PasswordPolicy;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OwnAccountService
{
    /** @param array<string, mixed> $data */
    public function update(User $user, array $data): User
    {
        if (isset($data['email']) && is_string($data['email'])) {
            $data['email'] = str($data['email'])->trim()->lower()->value();
        }
        if (isset($data['phone']) && is_string($data['phone'])) {
            $data['phone'] = trim($data['phone']);
        }

        $validated = Validator::make($data, [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'academic_level' => ['sometimes', 'required', 'string', 'max:150'],
            'address' => ['sometimes', 'required', 'string', 'max:500'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'required_without:phone', Rule::unique('users', 'email')->ignore($user)],
            'phone' => ['sometimes', 'nullable', 'required_without:email', 'regex:/^\+[1-9][0-9]{7,14}$/', Rule::unique('users', 'phone')->ignore($user)],
            'locale' => ['sometimes', 'required', Rule::in(['fr', 'ar'])],
            'current_password' => ['sometimes', 'nullable', 'string'],
        ])->validate();

        if (array_key_exists('email', $validated) && $validated['email'] !== $user->email) {
            $this->assertCurrentPassword($user, $validated['current_password'] ?? null);
            $user->email_verified_at = null;
        }
        if (array_key_exists('phone', $validated) && $validated['phone'] !== $user->phone) {
            $user->phone_verified_at = null;
        }

        $user->fill(Arr::only($validated, [
            'first_name', 'last_name', 'academic_level', 'address',
            'email', 'phone', 'locale',
        ]))->save();

        return $user->refresh();
    }

    /** @param array<string, mixed> $data */
    public function changePassword(User $user, array $data): void
    {
        $validated = Validator::make($data, [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ])->validate();

        $this->assertCurrentPassword($user, $validated['current_password']);

        $user->forceFill([
            'password' => $validated['password'],
            'remember_token' => str()->random(60),
        ])->save();

        // Sanctum AuthenticateSession conserve l'empreinte du mot de passe
        // dans chaque session. La réponse courante reçoit la nouvelle valeur ;
        // les autres sessions seront rejetées à leur prochaine requête.
    }

    private function assertCurrentPassword(User $user, mixed $currentPassword): void
    {
        if (! $user->identities()->where('provider', 'password')->exists() || $user->password === null) {
            throw ValidationException::withMessages(['current_password' => __('auth.password_identity_required')]);
        }

        $validated = Validator::make(
            ['current_password' => $currentPassword],
            ['current_password' => ['required', 'string']],
        )->validate();

        if (! Hash::check($validated['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('auth.current_password_invalid')]);
        }
    }
}
