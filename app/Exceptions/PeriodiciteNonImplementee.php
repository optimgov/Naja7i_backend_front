<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * L'enveloppe porte une fenêtre que le code ne sait pas compter.
 *
 * COMPTER FAUX EST PIRE QUE REFUSER. Une fenêtre mensuelle glissante, servie
 * par le compteur cumulatif, rendrait un reliquat plausible et faux — et le
 * candidat le lirait comme une mesure. C'est un état que le domaine interdit,
 * donc un refus en code et non un paramètre (ADR-0032).
 *
 * Aujourd'hui inatteignable : le type PostgreSQL `quota_periodicity` ne porte
 * qu'une valeur. Cette garde existe pour le jour où il en portera deux, et
 * `EnveloppeDeQuestions::FENETRE_IMPLEMENTEE` est le seul endroit à mettre à
 * jour ce jour-là.
 */
final class PeriodiciteNonImplementee extends RuntimeException
{
    public function __construct(public readonly string $fenetre)
    {
        parent::__construct("La fenêtre de quota « {$fenetre} » n'est pas comptée par ce code.");
    }
}
