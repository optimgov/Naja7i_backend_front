<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Sanctum, adapté à la topologie BFF (ADR-0004).
 *
 * `EnsureFrontendRequestsAreStateful` répond à une autre architecture : un SPA
 * qui appelle l'API DEPUIS le navigateur. Sanctum y reconnaît le premier tiers
 * à son en-tête `Referer` ou `Origin`, et n'active la session que dans ce cas.
 *
 * Ici, le navigateur ne parle jamais à l'API : il parle au BFF Nitro, qui
 * relaie de serveur à serveur. Un appel serveur-à-serveur ne porte ni
 * `Referer` ni `Origin` — la règle de Sanctum conclut donc « requête tierce »,
 * n'active pas la session, et toute l'authentification par cookie tombe. Le
 * symptôme est déroutant : « Session store not set on request » sur des routes
 * pourtant déclarées `statefulApi()`.
 *
 * On inverse donc la valeur par défaut : absence d'origine annoncée = appel du
 * BFF, donc first-party. Dès qu'une origine EST annoncée, la vérification de
 * Sanctum s'applique telle quelle — une page tierce qui poste vers l'API porte
 * toujours son `Origin` (le navigateur l'impose sur les requêtes de
 * changement d'état), elle échoue au filtre et n'obtient aucune session. La
 * validation du jeton CSRF, incluse dans la pile de Sanctum, reste en place.
 */
class EnsureBffRequestsAreStateful extends EnsureFrontendRequestsAreStateful
{
    /**
     * @param  Request  $request
     */
    public static function fromFrontend($request): bool
    {
        $announced = $request->headers->get('referer') ?: $request->headers->get('origin');

        if ($announced === null || $announced === '') {
            return true;
        }

        return parent::fromFrontend($request);
    }
}
