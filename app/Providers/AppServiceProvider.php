<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureEmailVerificationLink();
    }

    /**
     * Le lien de vérification par défaut de Laravel porte la clé INTERNE du
     * compte (`{id}`). Cette clé ne franchit jamais la frontière HTTP dans ce
     * projet (ADR-0002) : elle apprendrait à qui reçoit le lien combien de
     * comptes existent et dans quel ordre ils ont été créés. On signe donc une
     * URL bâtie sur l'uuid public.
     */
    private function configureEmailVerificationLink(): void
    {
        VerifyEmail::createUrlUsing(function (User $user): string {
            return URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'uuid' => $user->uuid,
                    'hash' => sha1($user->getEmailForVerification()),
                ]
            );
        });
    }
}
