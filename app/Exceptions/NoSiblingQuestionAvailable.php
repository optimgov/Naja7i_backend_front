<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Des rendez-vous sont échus, mais la banque ne tend aucun de leurs pièges.
 *
 * Situation DISTINCTE de « rien n'est échu » : le calendrier a bien du travail
 * à proposer, c'est le contenu qui manque. Le candidat n'a rien à corriger de
 * son côté et il n'a pas à revenir demain — la banque doit s'étoffer. Les deux
 * appellent donc des messages opposés, d'où deux codes d'erreur.
 *
 * On ne remplace jamais par une question d'à côté : servir autre chose que ce
 * que le calendrier a promis ferait croire au candidat qu'il a travaillé son
 * erreur.
 */
final class NoSiblingQuestionAvailable extends RuntimeException
{
    public function __construct(public readonly int $echus)
    {
        parent::__construct(
            "Aucune question sœur disponible pour les {$echus} rendez-vous échus."
        );
    }
}
