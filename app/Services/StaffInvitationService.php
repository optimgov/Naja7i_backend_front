<?php

namespace App\Services;

use App\Models\Identity;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use App\Validation\PasswordPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StaffInvitationService
{
    /**
     * Émet et envoie une invitation. À appeler dans la transaction qui crée
     * le compte : une erreur d'envoi ne doit jamais laisser un compte partiel.
     */
    public function issue(User $user, User $actor): StaffInvitation
    {
        $plainToken = Str::random(64);

        StaffInvitation::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $invitation = StaffInvitation::create([
            'user_id' => $user->id,
            'invited_by' => $actor->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addHours((int) config('naja7i.staff_invitation.expire_hours')),
        ]);

        $user->notify(new StaffInvitationNotification($plainToken));

        return $invitation;
    }

    public function accept(string $plainToken, string $password, string $confirmation): User
    {
        return DB::transaction(function () use ($plainToken, $password, $confirmation): User {
            $invitation = StaffInvitation::query()
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if ($invitation === null
                || $invitation->consumed_at !== null
                || $invitation->revoked_at !== null
                || $invitation->expires_at->isPast()) {
                throw ValidationException::withMessages(['token' => __('auth.invitation_invalid')]);
            }

            $validated = Validator::make([
                'password' => $password,
                'password_confirmation' => $confirmation,
            ], [
                'password' => ['required', 'confirmed', PasswordPolicy::rule()],
            ])->validate();

            $user = User::query()->lockForUpdate()->findOrFail($invitation->user_id);
            $user->forceFill(['password' => $validated['password']])->save();

            Identity::firstOrCreate([
                'user_id' => $user->id,
                'provider' => 'password',
            ], ['last_used_at' => null]);

            $invitation->forceFill(['consumed_at' => now()])->save();

            return $user->refresh();
        });
    }
}
