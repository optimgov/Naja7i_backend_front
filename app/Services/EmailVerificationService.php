<?php

namespace App\Services;

use App\Models\User;
use App\Models\VerificationToken;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Émission et consommation des jetons de vérification d'e-mail.
 */
final class EmailVerificationService
{
    private const TTL_HOURS = 24;

    /**
     * Émet un jeton neuf et envoie le message. Les jetons antérieurs encore
     * valides sont invalidés : un seul lien actif à la fois, sinon un lien
     * intercepté reste utilisable après que le candidat en a redemandé un.
     */
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
     * Consomme un jeton et marque l'e-mail vérifié.
     * Retourne l'utilisateur, ou null si le jeton est invalide, expiré ou déjà
     * consommé — un seul cas de retour pour les trois, afin de ne pas indiquer
     * à un attaquant laquelle des trois situations il a rencontrée.
     */
    public function consume(string $plainToken): ?User
    {
        $token = VerificationToken::where('token_hash', hash('sha256', $plainToken))
            ->where('purpose', VerificationToken::PURPOSE_EMAIL)
            ->first();

        if ($token === null || ! $token->isUsable()) {
            return null;
        }

        return DB::transaction(function () use ($token) {
            $token->update(['consumed_at' => now()]);

            $user = $token->user;

            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return $user;
        });
    }
}
