<?php

namespace App\Services\Paiement;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaiementRefuse;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\AbonnementService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Le paiement SIMULÉ — recette et démonstration, jamais la production.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA GARDE EST STRUCTURELLE : IL REFUSE DE S'INSTANCIER EN PRODUCTION
 *
 * Elle est dans le CONSTRUCTEUR, et pas dans une méthode, parce qu'un objet qui
 * ne peut pas exister ne peut pas être appelé par erreur. Un contrôle à
 * l'usage laisserait un chemin — une commande artisan, un job, un futur
 * contrôleur — appeler un paiement gratuit en production.
 *
 * Et elle est ici, dans la classe elle-même, PLUTÔT QUE DANS LE ROUTAGE : une
 * route non déclarée protège une porte, une exception au constructeur protège
 * la maison. Les deux sont posées — la route n'existe pas en production non
 * plus — mais c'est celle-ci qui tient si l'autre est oubliée.
 *
 * Ce que ce refus vaut est simple à dire : sans lui, un déploiement mal
 * configuré offrirait l'abonnement à qui connaît l'URL.
 */
final class SimulatedGateway implements PaymentGateway
{
    public function __construct(private readonly AbonnementService $abonnements)
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'Le paiement simulé ne peut pas exister en production : '
                .'il ouvrirait un abonnement sans contrepartie.'
            );
        }
    }

    public function moyen(): string
    {
        return 'simule';
    }

    /**
     * Ouvre ET honore dans la foulée — c'est ce qui le rend utile en recette.
     *
     * La commande passe quand même par `en_attente` puis `honorer()` : elle ne
     * naît pas honorée d'un trait. Emprunter le chemin normal garantit que ce
     * que la recette éprouve est bien ce que la production fera — octrois
     * posés par le même service, échéance calculée de la même façon.
     *
     * @param  array<string, mixed>  $parametres
     */
    public function ouvrir(User $user, array $parametres, string $idempotencyKey): Order
    {
        $code = (string) ($parametres['plan_code'] ?? '');

        $plan = Plan::active()->where('code', $code)->first();

        if ($plan === null) {
            throw new PaiementRefuse('plan_introuvable');
        }

        $commande = DB::transaction(fn () => Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'amount_cents' => $plan->price_cents,
            'currency' => $plan->currency,
            'external_reference' => 'SIMULATION',
            'idempotency_key' => $idempotencyKey,
            'idempotency_fingerprint' => hash('sha256', 'simule|'.$plan->code),
            'status' => 'en_attente',
            'method' => $this->moyen(),
        ]));

        /* Aucun validateur humain : c'est une simulation, et `validated_by`
         * reste nul. La piste d'audit doit pouvoir distinguer un droit ouvert
         * par une personne d'un droit ouvert par un automate. */
        return $this->abonnements->honorer($commande);
    }
}
