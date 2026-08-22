<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * La borne anti-aspiration des miroirs, sur un même couple — Q-16, Q-22.
 *
 * Le miroir ne consomme aucune unité, et c'est une décision du propriétaire.
 * Elle n'est tenable que si le chemin reste AUTO-BORNÉ : un miroir naît d'une
 * erreur, une erreur naît d'une question, et toute question est décomptée à son
 * service — le chemin est donc payé en amont. Le seul risque résiduel est la
 * boucle sur un même couple (compétence, cause), et c'est ce que cette borne
 * ferme.
 */
final class MirrorQuotaReached extends RuntimeException
{
    public function __construct(public readonly string $cause, public readonly int $borne)
    {
        parent::__construct("Borne de {$borne} miroirs atteinte pour la cause {$cause}.");
    }
}
