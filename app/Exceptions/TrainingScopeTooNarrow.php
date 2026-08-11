<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Le périmètre demandé ne permet pas une session utile.
 *
 * Exception PROPRE, distincte du `RuntimeException` que lève le diagnostic
 * quand sa série est incomplète : les deux situations se ressemblent mais
 * n'appellent ni le même code d'erreur ni la même conduite côté interface.
 *
 *  - Diagnostic incomplet : l'épreuve entière manque de questions, il n'y a
 *    rien à proposer au candidat.
 *  - Périmètre trop étroit : la banque est peut-être fournie ailleurs ; le
 *    candidat peut choisir un autre nœud, ou attendre que celui-ci se remplisse.
 *
 * Confondre les deux ferait afficher « le diagnostic n'est pas disponible » à
 * quelqu'un qui voulait s'entraîner sur un point précis.
 */
final class TrainingScopeTooNarrow extends RuntimeException
{
    public function __construct(
        public readonly int $disponibles,
        public readonly int $composees,
    ) {
        parent::__construct(
            "Périmètre trop étroit pour une session utile : {$composees} question(s) composable(s), ".
            "{$disponibles} publiée(s) dans ce périmètre."
        );
    }
}
