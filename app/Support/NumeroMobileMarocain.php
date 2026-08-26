<?php

namespace App\Support;

final class NumeroMobileMarocain
{
    public const REGLE = '/^\+212[67][0-9]{8}$/';

    public static function normaliser(mixed $numero): mixed
    {
        if (! is_string($numero)) {
            return $numero;
        }

        $compact = preg_replace('/[\s.()-]+/', '', trim($numero));

        if (preg_match('/^0([67][0-9]{8})$/', $compact, $correspondance) === 1) {
            return '+212'.$correspondance[1];
        }

        return $compact;
    }
}
