<?php

namespace App\Services;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Limitation des tentatives de connexion sur TROIS agrégats (correction D2-08).
 *
 * Un seul compteur e-mail+IP est facile à contourner :
 *  - l'attaquant qui cible un compte fait tourner ses IP ;
 *  - l'attaquant qui teste un mot de passe courant sur beaucoup de comptes
 *    (« password spraying ») garde une IP mais change d'e-mail à chaque essai.
 *
 * Les trois compteurs sont donc indépendants et cumulatifs.
 * L'e-mail est haché dans la clé : les clés de cache ne doivent pas contenir
 * d'adresses en clair.
 */
final class LoginThrottle
{
    /** @return array{limited: bool, retry_after: int} */
    public function check(string $email, string $ip): array
    {
        foreach ($this->keys($email, $ip) as $name => [$key, $config]) {
            if (RateLimiter::tooManyAttempts($key, $config['attempts'])) {
                return ['limited' => true, 'retry_after' => RateLimiter::availableIn($key)];
            }
        }

        return ['limited' => false, 'retry_after' => 0];
    }

    public function hit(string $email, string $ip): void
    {
        foreach ($this->keys($email, $ip) as [$key, $config]) {
            RateLimiter::hit($key, $config['decay_seconds']);
        }
    }

    /** Après une connexion réussie, seuls les compteurs liés au compte sont remis à zéro. */
    public function clear(string $email, string $ip): void
    {
        $keys = $this->keys($email, $ip);

        RateLimiter::clear($keys['per_email_ip'][0]);
        RateLimiter::clear($keys['per_email'][0]);
        // per_ip n'est PAS remis à zéro : une seule réussite ne doit pas
        // effacer le compteur d'une IP qui teste des dizaines de comptes.
    }

    /** @return array<string, array{0: string, 1: array{attempts: int, decay_seconds: int}}> */
    private function keys(string $email, string $ip): array
    {
        $config = config('naja7i.login_throttle');
        $hash = hash('sha256', mb_strtolower(trim($email)));

        return [
            'per_email_ip' => ["login:ei:{$hash}:{$ip}", $config['per_email_ip']],
            'per_email' => ["login:e:{$hash}",        $config['per_email']],
            'per_ip' => ["login:i:{$ip}",          $config['per_ip']],
        ];
    }
}
