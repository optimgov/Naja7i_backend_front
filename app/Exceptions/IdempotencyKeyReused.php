<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * La clé d'idempotence a déjà servi pour une AUTRE opération.
 *
 * Rendre la tentative préexistante serait le pire des comportements : le client
 * croirait avoir ouvert ce qu'il demandait, et recevrait un diagnostic là où il
 * attendait un entraînement — d'un autre concours au besoin. Pire encore, les
 * gardes d'ouverture ne seraient jamais atteintes : « rien à réviser » et
 * « périmètre trop étroit » se contourneraient par restitution silencieuse.
 *
 * Un refus explicite est la seule issue honnête. Le client change de clé.
 */
final class IdempotencyKeyReused extends RuntimeException
{
    public function __construct(public readonly string $cle)
    {
        parent::__construct(
            "La clé d'idempotence « {$cle} » a déjà servi pour une opération différente."
        );
    }
}
