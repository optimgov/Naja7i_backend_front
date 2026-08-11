<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interdit tout stockage de la réponse, y compris par un intermédiaire.
 *
 * POURQUOI CE N'EST PAS DE LA PRUDENCE GÉNÉRIQUE. `seconds_remaining` est
 * calculé par le serveur au moment de la réponse — c'est la règle du PAS-6 :
 * l'horloge du client n'est jamais autoritative. Une réponse rejouée depuis un
 * cache rendrait donc un chronomètre FAUX, et d'autant plus faux qu'elle serait
 * gardée longtemps : un candidat rechargeant sa page verrait le temps s'arrêter.
 *
 * `no-store` plutôt que `no-cache` : `no-cache` autorise le stockage à
 * condition de revalider, ce qui laisse la réponse sur le disque d'un relais
 * partagé. Ici, la charge utile est nominative — elle ne doit pas y séjourner.
 *
 * Déclaré sur la ROUTE, comme l'autorisation : une contrainte de transport se
 * lit dans la table des routes, elle ne se devine pas dans un contrôleur.
 */
class NoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
