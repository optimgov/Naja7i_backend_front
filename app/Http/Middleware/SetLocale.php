<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Langue de réponse : préférence du compte, sinon Accept-Language, sinon fr.
 * Les messages d'erreur sont affichés tels quels par le frontend : ils doivent
 * être dans la langue du candidat, pas dans celle du serveur.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $preferred = $request->getPreferredLanguage(['fr', 'ar']);

        App::setLocale(
            $request->user()?->locale
            ?? (in_array($preferred, ['fr', 'ar'], true) ? $preferred : 'fr')
        );

        return $next($request);
    }
}
