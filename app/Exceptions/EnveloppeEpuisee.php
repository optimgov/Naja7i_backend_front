<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Le compte porte `questions.answer`, mais son enveloppe est à zéro.
 *
 * DISTINCT DU MUR DE M-007, et la distinction compte pour le candidat : là-bas
 * la fonction n'est pas dans son accès, ici elle y est et les unités sont
 * consommées. Les deux conduites diffèrent — souscrire, ou renouveler.
 *
 * Ce refus n'arrive QUE lorsqu'il ne reste rien. Au-dessus de zéro, la série
 * se compose au reliquat : refuser deux questions à qui en a payé deux ferait
 * perdre définitivement des unités déjà achetées.
 */
final class EnveloppeEpuisee extends RuntimeException
{
    public function __construct(public readonly int $reliquat = 0)
    {
        parent::__construct('Enveloppe de questions épuisée.');
    }
}
