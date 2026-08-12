<?php

namespace App\Filament;

/**
 * Les libellés partagés du back-office.
 *
 * Les huit causes d'erreur sont un ENUM PostgreSQL (`error_cause`, PAS-5) : la
 * liste des clés fait foi en base, seule leur traduction vit ici. Elle est
 * partagée parce que le formulaire de rédaction et la page de couverture
 * désignent les mêmes couples — deux traductions divergentes feraient croire à
 * deux causes différentes, et le plan de rédaction ne se raccorderait plus à ce
 * qu'on saisit.
 */
final class Libelles
{
    /** @return array<string, string> */
    public static function causes(): array
    {
        return [
            'confusion_notions' => 'Confusion entre notions',
            'lecture_enonce' => 'Lecture de l\'énoncé',
            'regle_mal_appliquee' => 'Règle mal appliquée',
            'connaissance_absente' => 'Connaissance absente',
            'source_perimee' => 'Source périmée',
            'calcul' => 'Calcul',
            'piege_formulation' => 'Piège de formulation',
            'indetermine' => 'Indéterminé',
        ];
    }

    public static function cause(?string $code): string
    {
        return self::causes()[$code] ?? (string) $code;
    }
}
