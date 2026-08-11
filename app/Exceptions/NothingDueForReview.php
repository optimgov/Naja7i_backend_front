<?php

namespace App\Exceptions;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Aucun rendez-vous à réviser aujourd'hui.
 *
 * N'EST PAS UNE ERREUR EN LECTURE. La route `due` répond 200 avec une liste
 * vide et la prochaine date : « rien aujourd'hui, prochain le 14 » est une
 * information utile, un 404 ne l'est pas. Cette exception ne concerne que
 * l'OUVERTURE d'une session — composer une série de zéro question n'a pas de
 * sens, et le client doit savoir qu'il n'a pas à ouvrir d'écran.
 *
 * Elle porte la prochaine échéance pour que la réponse d'erreur dise elle
 * aussi quand revenir, plutôt que de renvoyer le client à un second appel.
 */
final class NothingDueForReview extends RuntimeException
{
    public function __construct(public readonly ?CarbonImmutable $prochaine)
    {
        parent::__construct(
            $prochaine === null
                ? 'Aucun rendez-vous de révision : le calendrier est vide.'
                : "Aucun rendez-vous échu aujourd'hui. Prochain le {$prochaine->toDateString()}."
        );
    }
}
