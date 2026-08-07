<?php

namespace App\Http\Middleware;

use App\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Premier middleware de la chaîne. Reprend le X-Request-Id fourni par le BFF
 * s'il existe, en génère un sinon, et le renvoie systématiquement dans la
 * réponse pour que le frontend puisse l'afficher au candidat en cas d'erreur.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header(RequestId::HEADER) ?: (string) Str::uuid7();

        // On n'accepte pas un identifiant arbitraire venu du client.
        if (! preg_match('/^[A-Za-z0-9\-]{8,64}$/', $id)) {
            $id = (string) Str::uuid7();
        }

        RequestId::set($id);

        $response = $next($request);
        $response->headers->set(RequestId::HEADER, $id);

        return $response;
    }
}
