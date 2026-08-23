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
        [$invitation, $plainToken] = $this->forger($user, $actor);

        $user->notify(new StaffInvitationNotification($plainToken));

        return $invitation;
    }

    /**
     * L'INVITATION D'AMORÇAGE — sans invitant, et sans courriel (M-018).
     *
     * ═══════════════════════════════════════════════════════════════════════
     * DEUX DIFFÉRENCES, ET TOUTES DEUX SONT LA RAISON D'ÊTRE DE CETTE MÉTHODE
     *
     * **Aucun invitant.** L'amorçage d'une machine neuve n'en a pas, et faire
     * pointer l'invitation vers le compte qu'elle crée écrirait qu'il s'est
     * invité lui-même. `invited_by` nul dit le fait — même raisonnement que
     * `plan_versions.composed_by`.
     *
     * **Aucun envoi.** Une préproduction fraîche n'a pas nécessairement de
     * canal de courriel configuré ; faire dépendre la sortie du cercle vicieux
     * d'un SMTP qui n'existe pas remplacerait un blocage par un autre. Le jeton
     * est donc RENDU à l'appelant, qui l'imprime — et il ne transite ni par la
     * ligne de commande, ni par un journal d'envoi.
     *
     * Le jeton reste ce qu'il est partout ailleurs : opaque, haché en base, à
     * usage unique, et daté. `accept()` ne fait aucune différence entre les
     * deux origines, et c'est voulu — il n'y a qu'un seul chemin pour poser un
     * mot de passe.
     *
     * @return array{0: StaffInvitation, 1: string} l'invitation et son jeton en clair
     */
    public function issueForBootstrap(User $user): array
    {
        return $this->forger($user, null);
    }

    /**
     * Le cœur commun : révoquer les invitations pendantes, en poser une neuve.
     *
     * La révocation préalable n'est pas une politesse : deux invitations
     * valides pour un même compte donneraient deux chemins pour poser un mot de
     * passe, et le plus ancien survivrait à la révocation du plus récent.
     *
     * @return array{0: StaffInvitation, 1: string}
     */
    private function forger(User $user, ?User $actor): array
    {
        $plainToken = Str::random(64);

        StaffInvitation::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $invitation = StaffInvitation::create([
            'user_id' => $user->id,
            'invited_by' => $actor?->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addHours((int) config('naja7i.staff_invitation.expire_hours')),
        ]);

        return [$invitation, $plainToken];
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
