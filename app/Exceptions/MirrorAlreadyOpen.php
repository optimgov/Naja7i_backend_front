<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Un miroir est déjà ouvert, mais sur un AUTRE item.
 *
 * AUDIT TOURNÉE 2, BLOC-3. Le miroir se reprenait comme l'entraînement ou la
 * révision : n'importe quelle session ouverte du même genre. Mais leurs charges
 * utiles ne décrivent pas la même chose — une séance de révision est « ce qui
 * est échu », interchangeable ; un miroir est « la vérification de CET item ».
 *
 * Rendre le miroir de l'item A en réponse à une demande sur l'item B servait
 * une question sans rapport, accompagnée de la cause et de la question source
 * de la demande PERDANTE. Le candidat croyait vérifier une leçon, il en
 * révisait une autre sous une étiquette fausse.
 *
 * On refuse donc, plutôt que de fabriquer une réponse cohérente en apparence.
 * Le candidat termine ou abandonne le miroir en cours — c'est le sens de
 * « un seul ouvert à la fois ».
 */
final class MirrorAlreadyOpen extends RuntimeException
{
    public function __construct(public readonly string $uuidOuvert)
    {
        parent::__construct(
            "Un miroir est déjà ouvert sur un autre item ({$uuidOuvert})."
        );
    }
}
