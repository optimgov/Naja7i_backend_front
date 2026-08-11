<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Aucune autre question ne tend ce piège dans cette compétence.
 *
 * ON NE RESSERT JAMAIS L'ÉNONCÉ DÉJÀ RÉPONDU. La révision, elle, s'y rabat —
 * mieux vaut retravailler un énoncé connu que sauter une échéance. Le miroir
 * ne le peut pas : sa raison d'être est de vérifier que l'explication a pris
 * SUR UN AUTRE ÉNONCÉ. Resservir celui que le candidat vient de corriger ne
 * vérifierait rien, et lui ferait croire le contraire.
 *
 * C'EST UN TROU DE BANQUE, PAS UN CAS LIMITE. Le couple concerné apparaît de
 * lui-même au plan de rédaction du PAS-22 : l'erreur a créé un rendez-vous, et
 * `CouvertureBanque` liste les couples attendus que la banque ne couvre pas.
 * Aucun recensement supplémentaire n'est nécessaire ici.
 */
final class NoMirrorAvailable extends RuntimeException
{
    public function __construct(public readonly string $cause)
    {
        parent::__construct(
            "Aucune question miroir disponible pour la cause « {$cause} »."
        );
    }
}
