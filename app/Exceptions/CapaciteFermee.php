<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Le compte ne porte aucun droit couvrant la capacité demandée.
 *
 * LA SECONDE SERRURE. Les contrôleurs refusent déjà par `MurPayant` avant
 * d'appeler le service — c'est là que le refus se formule pour le candidat.
 * Celle-ci protège la maison plutôt que la porte : sans elle, une capacité
 * fermée et une capacité illimitée se ressemblent (aucune enveloppe
 * gouvernante dans les deux cas), et un futur chemin d'ouverture qui
 * oublierait le mur servirait des questions gratuitement à qui n'a plus rien.
 *
 * Même raisonnement que `SimulatedGateway`, qui refuse de s'instancier en
 * production alors que sa route n'y est pas déclarée.
 */
final class CapaciteFermee extends RuntimeException
{
    public function __construct(public readonly string $capacite)
    {
        parent::__construct("Aucun droit actif ne couvre {$capacite}.");
    }
}
