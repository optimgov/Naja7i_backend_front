<?php

namespace App\Services\Paiement;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaiementRefuse;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use App\Services\PlanVersionService;
use Illuminate\Support\Facades\DB;

/**
 * Le coupon cadeau — DEUX TEMPS, et le second est humain.
 *
 * Le candidat saisit un code : une commande naît EN ATTENTE. Un membre de
 * l'équipe la valide en back-office : elle est honorée, les octrois
 * apparaissent. **Un coupon ne donne jamais de droit sans un humain qui
 * clique.**
 *
 * Ce n'est pas une prudence excessive, c'est ce qui rend le moyen utilisable
 * sans prestataire : quelqu'un a reçu un virement, quelqu'un le confirme. Un
 * coupon qui ouvrirait seul serait de la monnaie au porteur, et le premier
 * code fuité coûterait autant que le nombre de fois où il serait recopié.
 *
 * L'USAGE EST RÉSERVÉ À LA SAISIE, pas à la validation : sans cela, cinquante
 * et une personnes saisiraient un coupon de cinquante et l'équipe découvrirait
 * le dépassement au moment de valider.
 */
final class CouponGateway implements PaymentGateway
{
    public function __construct(private readonly PlanVersionService $versions) {}

    public function moyen(): string
    {
        return 'coupon';
    }

    /** @param array<string, mixed> $parametres */
    public function ouvrir(User $user, array $parametres, string $idempotencyKey): Order
    {
        /* Insensible à la casse et aux espaces : un code se recopie d'une
         * capture d'écran ou se dicte au téléphone. Refuser « nj7-abcd » quand
         * « NJ7-ABCD » existe serait une friction gratuite. */
        $code = mb_strtoupper(trim((string) ($parametres['code'] ?? '')));

        if ($code === '') {
            throw new PaiementRefuse('code_absent');
        }

        return DB::transaction(function () use ($user, $parametres, $code, $idempotencyKey) {
            /* VERROU SUR LE COUPON, et il est nécessaire : deux saisies
             * simultanées du dernier usage passeraient toutes deux le contrôle
             * avant que l'une n'incrémente. */
            $coupon = Coupon::where('code', $code)->lockForUpdate()->first();

            if ($coupon === null) {
                throw new PaiementRefuse('introuvable');
            }

            if (! $coupon->estUtilisable()) {
                throw new PaiementRefuse($coupon->motifDeRefus() ?? 'indisponible');
            }

            $plan = $coupon->plan()->firstOrFail();

            if (! $plan->active) {
                throw new PaiementRefuse('plan_inactif');
            }

            $version = $this->versions->purchasable(
                $plan,
                isset($parametres['version_uuid']) ? (string) $parametres['version_uuid'] : null,
            );

            $commande = Order::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'plan_version_id' => $version->id,
                'coupon_id' => $coupon->id,
                /* LE MONTANT EST FIGÉ MAINTENANT — le prix du plan peut changer
                 * avant la validation, la commande ne bouge pas. */
                'amount_cents' => $version->price_cents,
                'currency' => $version->currency,
                'external_reference' => $coupon->code,
                'idempotency_key' => $idempotencyKey,
                'idempotency_fingerprint' => hash('sha256', 'coupon|'.$code),
                'status' => 'en_attente',
                'method' => $this->moyen(),
            ]);

            $coupon->increment('used_count');

            if ($coupon->fresh()->used_count >= $coupon->max_uses) {
                $coupon->update(['status' => 'epuise']);
            }

            return $commande;
        });
    }
}
