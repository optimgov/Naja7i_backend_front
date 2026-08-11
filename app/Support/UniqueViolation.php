<?php

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * Reconnaissance d'une violation d'index unique PRÉCISE.
 *
 * Un `catch (QueryException)` large est un piège : il avalerait une colonne
 * manquante, une clé étrangère orpheline ou une contrainte de contrôle, et le
 * défaut ressortirait plus tard sous une forme méconnaissable. On ne rattrape
 * que l'index qu'on attend, nommément.
 *
 * `23505` est le SQLSTATE `unique_violation` de PostgreSQL. Le nom de l'index
 * figure dans le détail du message renvoyé par le pilote ; le comparer par
 * `str_contains` est le seul moyen portable, PDO n'exposant pas le nom de la
 * contrainte dans un champ dédié.
 */
final class UniqueViolation
{
    private const SQLSTATE_UNIQUE = '23505';

    public static function on(QueryException $e, string $index): bool
    {
        if (($e->errorInfo[0] ?? null) !== self::SQLSTATE_UNIQUE) {
            return false;
        }

        return str_contains($e->getMessage(), $index);
    }

    /** @param  list<string>  $index */
    public static function onAny(QueryException $e, array $index): bool
    {
        foreach ($index as $nom) {
            if (self::on($e, $nom)) {
                return true;
            }
        }

        return false;
    }
}
