<?php

namespace App\Services;

use App\Contracts\AccessGrant;
use App\Models\AccessGrantRecord;
use App\Models\Order;
use App\Models\PlanVersion;
use App\Models\User;
use App\Support\CapabilityRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Honorer une commande, et rien d'autre.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * UN SEUL ENDROIT POSE LES OCTROIS
 *
 * Trois moyens de paiement — coupon, simulé, et demain un prestataire — mènent
 * ici. S'ils posaient chacun leurs octrois, on aurait trois façons subtilement
 * différentes d'ouvrir un abonnement, et la troisième révélerait les écarts des
 * deux premières un an plus tard.
 *
 * L'octroi est la CONSÉQUENCE de la commande, jamais un geste séparé :
 * `origin_reference` porte l'uuid de la commande, et c'est ce qui rend la
 * chaîne relisible — un droit sans commande est un droit qu'on ne sait pas
 * expliquer.
 */
final class AbonnementService
{
    public function __construct(private readonly CapabilityRegistry $capabilities) {}

    /**
     * Honore une commande : elle produit exactement un octroi par capacité.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * IDEMPOTENT ET ATOMIQUE
     *
     * Rejouer ne crée pas un second octroi. Trois gardes, et la troisième est
     * la seule qui tienne si les deux autres sont contournées :
     *
     *   1. l'état est relu SOUS VERROU — une commande déjà honorée est rendue
     *      telle quelle, comme `AttemptService::submit()` depuis le PAS-6 ;
     *   2. tout est dans UNE transaction — un octroi partiel n'existe pas ;
     *   3. un index unique `(user_id, capability, origin_reference)` en base.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * L'ÉCHÉANCE SE CALCULE ICI, PAS À LA COMMANDE
     *
     * Un coupon saisi lundi et validé jeudi donne trente jours pleins À PARTIR
     * DE JEUDI. Le candidat n'a pas à payer la lenteur de l'équipe — et compter
     * depuis la saisie ferait d'un délai de traitement une amputation du droit
     * acheté.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * LA PROLONGATION EMPILE
     *
     * Acheter un second mois pendant que le premier court REPOUSSE l'échéance,
     * il ne l'écrase pas. Le nouvel octroi démarre donc à la fin du droit
     * courant, pas maintenant. Un candidat qui achète deux mois a deux mois —
     * l'inverse serait un vol légal et un défaut de confiance irréparable.
     */
    public function honorer(Order $commande, ?User $par = null): Order
    {
        return DB::transaction(function () use ($commande, $par) {
            $verrouillee = Order::where('id', $commande->id)->lockForUpdate()->first();

            if ($verrouillee === null) {
                throw new RuntimeException('Commande introuvable.');
            }

            /* Déjà honorée — ou close autrement : on rend l'état, on n'agit
             * pas. C'est le rejeu, et il est sans effet par construction. */
            if ($verrouillee->status !== 'en_attente') {
                return $verrouillee;
            }

            $plan = $verrouillee->plan()->firstOrFail();
            $version = $verrouillee->planVersion()->firstOrFail();
            $maintenant = now();

            $this->octroyerLesDroits(
                $verrouillee->user_id,
                $version,
                'purchase',
                $verrouillee->uuid,
                "Plan {$plan->code} v{$version->version}",
            );

            $verrouillee->update([
                'status' => 'honoree',
                'honored_at' => $maintenant,
                'validated_by' => $par?->id,
                'validated_at' => $par !== null ? $maintenant : null,
            ]);

            return $verrouillee->fresh();
        });
    }

    /**
     * POSER LES OCTROIS D'UNE VERSION — le seul endroit qui le fait.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * POURQUOI CETTE MÉTHODE EXISTE
     *
     * Un achat et une inscription ouvrent le même genre de droit : une capacité
     * par ligne, l'échéance calculée sur la durée de la VERSION, l'enveloppe
     * lue sur son instantané. Ce qui diffère tient en trois chaînes — l'origine,
     * la référence et la note. Laisser l'attribution gratuite écrire ses propres
     * `AccessGrantRecord::create()` aurait produit un SECOND CIRCUIT D'OCTROI :
     * deux façons subtilement différentes d'ouvrir un droit, et la troisième
     * révélerait les écarts des deux premières un an plus tard.
     *
     * L'ORIGINE EST UN PARAMÈTRE, PAS UNE CONSTANTE : `purchase` pour un achat,
     * `account_level` pour ce que le compte reçoit à l'inscription,
     * `rattrapage` pour ce qu'une commande d'administration pose après coup.
     * Aucun agrégat de vente ne doit compter un droit que personne n'a acheté.
     *
     * @return int le nombre d'octrois posés
     */
    public function octroyerLesDroits(
        int $userId,
        PlanVersion $version,
        string $origine,
        string $reference,
        string $note,
    ): int {
        $maintenant = now();
        $poses = 0;

        foreach ($this->capacitesDe($version->capabilities) as $capacite) {
            $depart = $this->departDe($userId, $capacite, $maintenant);

            AccessGrantRecord::create([
                'user_id' => $userId,
                'capability' => $capacite,
                'scope_uuid' => null,
                'starts_at' => $depart,
                'ends_at' => $version->duration_days === null
                    ? null
                    : $depart->copy()->addDays($version->duration_days),
                'origin' => $origine,
                'origin_reference' => $reference,
                'note' => $note,
                /*
                 * L'ENVELOPPE VIENT DE LA VERSION, JAMAIS DU PROFIL.
                 *
                 * `quota_profiles` est amendable : le relire ici livrerait
                 * à une commande d'hier la valeur d'aujourd'hui, ce qui est
                 * le défaut V-3 sous un autre nom. La version porte
                 * l'instantané figé à sa composition — c'est ce qui a été
                 * vendu, et c'est ce qui s'ouvre.
                 */
            ] + $version->enveloppePour($capacite));

            $poses++;
        }

        return $poses;
    }

    /**
     * Refuse une commande en attente. Le motif est INTERNE.
     *
     * Il se lit en back-office et ne sort jamais vers le candidat — même règle
     * que DET-50 : « virement non reçu » ou « coupon revendu » regarde
     * l'équipe, pas la personne en face. Le candidat apprend que sa demande
     * n'a pas abouti, et il a une adresse pour en parler.
     *
     * UN REFUS N'OUVRE RIEN, et ne rend pas non plus l'usage du coupon : le
     * compteur reste consommé. Le rendre permettrait de ressaisir en boucle un
     * code refusé, ce qui est exactement ce qu'un refus veut arrêter.
     */
    public function refuser(Order $commande, User $par, string $motif): Order
    {
        return DB::transaction(function () use ($commande, $par, $motif) {
            $verrouillee = Order::where('id', $commande->id)->lockForUpdate()->first();

            if ($verrouillee === null || $verrouillee->status !== 'en_attente') {
                return $verrouillee ?? $commande;
            }

            $verrouillee->update([
                'status' => 'annulee',
                'validated_by' => $par->id,
                'validated_at' => now(),
                'refusal_reason' => $motif,
            ]);

            return $verrouillee->fresh();
        });
    }

    /**
     * L'état d'abonnement d'un candidat.
     *
     * `capabilities()` vient d'`AccessGrant` : c'est la MÊME lecture que celle
     * du mur payant. Un écran d'abonnement qui interrogerait les commandes
     * afficherait ce que le candidat a acheté, pas ce dont il dispose — et les
     * deux divergent dès qu'un octroi expire.
     *
     * @return array<string, mixed>
     */
    public function etat(User $user, AccessGrant $droits): array
    {
        $capacites = $droits->capabilities($user);

        /* L'échéance PAR CAPACITÉ : un candidat peut tenir une capacité d'un
         * plan et une autre d'un second, avec deux fins différentes. Rendre une
         * seule date serait faux dès le premier achat croisé. */
        $echeances = AccessGrantRecord::where('user_id', $user->id)
            ->active()
            ->get()
            ->groupBy('capability')
            ->map(function ($octrois) {
                /* Sans terme l'emporte : une capacité illimitée ne se réduit
                 * pas parce qu'un octroi daté existe à côté. */
                if ($octrois->contains(fn ($o) => $o->ends_at === null)) {
                    return null;
                }

                return $octrois->max('ends_at')?->toIso8601String();
            });

        return [
            'capabilities' => $capacites,
            'expires_at' => $echeances->all(),
            'pending_orders' => Order::where('user_id', $user->id)->enAttente()->count(),
        ];
    }

    /**
     * Les capacités d'un plan, nettoyées.
     *
     * ON N'OCTROIE QUE DES CAPACITÉS CONNUES. Un plan mal saisi en back-office
     * — une faute de frappe dans un tableau JSON — poserait sinon un octroi sur
     * une capacité que rien ne lit, et le candidat paierait pour un droit
     * inexistant sans que rien ne le signale.
     *
     * @param  list<string>|null  $demandees
     * @return list<string>
     */
    private function capacitesDe(?array $demandees): array
    {
        return $this->capabilities->assertCommercializable($demandees);
    }

    /**
     * Le départ d'un nouvel octroi : maintenant, ou la fin du droit courant.
     *
     * C'est ici que la prolongation empile. On prend la PLUS TARDIVE des fins
     * actives et datées pour cette capacité. Un droit sans terme reste effectif,
     * mais ne participe pas à ce calcul : le premier achat daté part maintenant,
     * puis les suivants se chaînent entre eux.
     */
    private function departDe(int $userId, string $capacite, Carbon $maintenant): Carbon
    {
        $courants = AccessGrantRecord::where('user_id', $userId)
            ->where('capability', $capacite)
            ->active()
            ->whereNotNull('ends_at')
            ->get();

        if ($courants->isEmpty()) {
            return $maintenant;
        }

        $fin = $courants->max('ends_at');

        return $fin !== null && $fin->isAfter($maintenant) ? $fin->copy() : $maintenant;
    }
}
