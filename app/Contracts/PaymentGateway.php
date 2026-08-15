<?php

namespace App\Contracts;

use App\Exceptions\PaiementRefuse;
use App\Models\Order;
use App\Models\User;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * L'ADAPTATEUR DE PAIEMENT — ET SA SEULE RAISON D'ÊTRE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Il n'y a pas de prestataire de paiement aujourd'hui. Il y en aura un demain :
 * CMI, ou un autre. Cette interface existe POUR QUE CE JOUR-LÀ NE TOUCHE À RIEN
 * D'AUTRE — ni au modèle de commande, ni au service d'honoration, ni aux
 * octrois, ni au mur payant.
 *
 * Deux implémentations sont livrées :
 *
 *   `CouponGateway`     le coupon cadeau, validé par un humain en back-office.
 *                       Il ouvre une commande EN ATTENTE : un titre à faire
 *                       valoir, pas une clé.
 *   `SimulatedGateway`  le paiement simulé, HORS PRODUCTION UNIQUEMENT. Il
 *                       honore immédiatement, pour la recette et la démo.
 *
 * La troisième — le vrai prestataire — s'ajoutera à côté. Elle ouvrira une
 * commande en attente, recevra une notification serveur à serveur, et appellera
 * le même `AbonnementService::honorer()` que les deux autres. C'est cela qu'on
 * achète en écrivant l'interface maintenant plutôt qu'alors.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE L'ADAPTATEUR NE FAIT PAS
 *
 * Il ne pose AUCUN octroi. Il ouvre une commande, et dit si elle naît honorée
 * ou en attente. Poser les droits est le travail d'`AbonnementService`, en un
 * seul endroit, pour que trois moyens de paiement ne produisent pas trois
 * façons subtilement différentes d'ouvrir un abonnement.
 */
interface PaymentGateway
{
    /** Le moyen, tel qu'il est enregistré sur la commande (`order_method`). */
    public function moyen(): string;

    /**
     * Ouvre une commande pour ce candidat.
     *
     * L'adaptateur résout le plan à partir de ses propres paramètres — un code
     * de coupon, un code de plan, un identifiant de session de paiement — et
     * décide de l'état initial de la commande.
     *
     * IDEMPOTENT par `$idempotencyKey` : deux clics n'ouvrent qu'une commande.
     *
     * @param  array<string, mixed>  $parametres
     *
     * @throws PaiementRefuse quand le moyen ne peut pas ouvrir
     */
    public function ouvrir(User $user, array $parametres, string $idempotencyKey): Order;
}
