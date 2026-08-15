<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Le moyen de paiement ne peut pas ouvrir la commande.
 *
 * PORTE UN MOTIF NOMMÉ, et c'est ce qui distingue « votre coupon a expiré » de
 * « ce code n'existe pas » — deux conduites différentes pour le candidat. Un
 * refus générique le laisserait ressaisir indéfiniment un code périmé.
 *
 * Le motif est un CODE, pas une phrase : la traduction se fait à la frontière
 * HTTP, en français comme en arabe.
 */
final class PaiementRefuse extends RuntimeException
{
    public function __construct(public readonly string $motif)
    {
        parent::__construct("Paiement refusé : {$motif}");
    }
}
