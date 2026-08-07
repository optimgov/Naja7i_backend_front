<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Identifiant de corrélation du cycle courant. Posé par le middleware
 * AssignRequestId au tout début de la chaîne, avant tout contrôleur, pour
 * qu'une erreur survenant n'importe où puisse s'y référer.
 */
final class RequestId
{
    public const HEADER = 'X-Request-Id';

    public static function current(): string
    {
        if (! app()->bound('naja7i.request_id')) {
            app()->instance('naja7i.request_id', (string) Str::uuid7());
        }

        return app('naja7i.request_id');
    }

    public static function set(string $id): void
    {
        app()->instance('naja7i.request_id', $id);
    }
}
