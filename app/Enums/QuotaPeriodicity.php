<?php

namespace App\Enums;

/**
 * La fenêtre d'un quota — fermée en code, à une seule valeur aujourd'hui.
 *
 * L'arbitrage Q-07 du 21 août 2026 est net : « Le quota de questions est
 * cumulatif sur la durée du droit. Un renouvellement crée une nouvelle
 * enveloppe. Un droit sans terme ne se remet pas automatiquement à zéro. »
 *
 * UNE ÉNUMÉRATION À UN SEUL CAS EST LE BON OBJET, pas une bizarrerie. La
 * périodicité est une RÈGLE DE CONSOMMATION, et la matrice des champs range
 * ces règles dans la colonne « X — code seul, inaccessible à
 * l'administration ». La porter en enum fermée dit exactement cela : elle se
 * lit, elle se stocke, et une seconde fenêtre — mensuelle, glissante — sera un
 * changement de code assorti de sa règle de remise à zéro, jamais une chaîne
 * saisie dans un formulaire.
 */
enum QuotaPeriodicity: string
{
    /** Cumulatif sur la durée du droit qui porte l'enveloppe (Q-07). */
    case CUMULATIVE_GRANT = 'cumulative_grant';

    public function label(): string
    {
        return match ($this) {
            self::CUMULATIVE_GRANT => 'cumulatif sur la durée du droit',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
