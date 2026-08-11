<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * L'item ne porte aucune erreur à vérifier.
 *
 * Réponse juste, ou pas de réponse du tout : il n'y a pas de piège dans lequel
 * le candidat soit tombé, donc rien à retendre. Un miroir servi là-dessus
 * ferait réviser ce qui n'a jamais posé problème — la règle que F07 tient
 * depuis son premier jour.
 *
 * 409 et non 404 : l'item existe et appartient bien au candidat. Le 404 est
 * réservé à ce qui ne LUI appartient pas — lui répondre « introuvable » sur son
 * propre item serait mentir pour rien.
 */
final class MirrorNotApplicable extends RuntimeException
{
    public function __construct(public readonly string $raison)
    {
        parent::__construct("Aucun miroir applicable : {$raison}.");
    }
}
