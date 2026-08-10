<?php

namespace App\Services;

use App\Models\User;
use App\Models\VerificationToken;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Émission et consommation des jetons de vérification.
 *
 * REVUE PAS-3 BLOC-1 — la consommation était lue puis écrite en deux temps.
 * Deux requêtes simultanées observaient toutes deux `consumed_at IS NULL` et
 * réussissaient. La garantie « usage unique » tombait précisément dans le seul
 * cas où elle compte : la concurrence.
 *
 * Elle repose désormais sur un UPDATE conditionnel unique. La base arbitre ;
 * l'application se contente de compter les lignes affectées.
 */
final class EmailVerificationService
{
    private const TTL_HOURS = 24;

    public function send(User $user): void
    {
        $plain = Str::random(64);

        DB::transaction(function () use ($user, $plain) {
            VerificationToken::where('user_id', $user->id)
                ->where('purpose', VerificationToken::PURPOSE_EMAIL)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            VerificationToken::create([
                'user_id' => $user->id,
                'token_hash' => hash('sha256', $plain),
                'purpose' => VerificationToken::PURPOSE_EMAIL,
                'expires_at' => now()->addHours(self::TTL_HOURS),
            ]);
        });

        $user->notify(new VerifyEmailNotification($plain));
    }

    /**
     * Consomme un jeton, ou rend null.
     *
     * Un seul appelant peut gagner : l'UPDATE conditionnel n'affecte une ligne
     * que si elle était encore libre et valide. Le perdant reçoit exactement le
     * même refus qu'un jeton inconnu — jeton invalide, expiré ou déjà consommé
     * ne se distinguent pas, pour ne rien apprendre à un attaquant.
     */
    public function consume(string $plainToken): ?User
    {
        $hash = hash('sha256', $plainToken);

        return DB::transaction(function () use ($hash) {
            $affectees = VerificationToken::where('token_hash', $hash)
                ->where('purpose', VerificationToken::PURPOSE_EMAIL)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->update(['consumed_at' => now()]);

            if ($affectees !== 1) {
                return null;
            }

            $token = VerificationToken::where('token_hash', $hash)->firstOrFail();
            $user = $token->user;

            if ($user->email_verified_at === null) {
                $user->markEmailAsVerified();
            }

            return $user->fresh();
        });
    }
}
