<?php

namespace App\Services;

use App\Contracts\AccessGrant;
use App\Models\AccessGrantRecord;
use App\Models\Order;
use App\Models\PlanVersion;
use App\Models\QuestionConsumption;
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
    /**
     * LES MOYENS DE PAIEMENT QUI CONVERTISSENT — ADR-0033.
     *
     * « Payante » se lit sur la MÉTHODE, jamais sur le montant. Un montant nul
     * n'est pas un critère : une offre à zéro peut être un forfait réel, et un
     * futur octroi d'expert ne passera pas par une commande commerciale.
     *
     * `coupon` convertit : c'est l'activation manuelle d'un forfait payé hors
     * ligne (décision D-C). `simule` ne convertit PAS — il n'existe pas en
     * production (`SimulatedGateway` refuse de s'y instancier), et le laisser
     * clore un essai ferait perdre en recette ce qu'aucun candidat n'a acheté.
     * Un prestataire réel s'ajoutera ici, et nulle part ailleurs.
     *
     * @var list<string>
     */
    public const MOYENS_QUI_CONVERTISSENT = ['coupon'];

    public function __construct(private readonly CapabilityRegistry $capabilities) {}

    /** Cette commande active-t-elle un forfait payé ? */
    public static function convertit(?string $moyen): bool
    {
        return $moyen !== null && in_array($moyen, self::MOYENS_QUI_CONVERTISSENT, true);
    }

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

            /*
             * LE VERROU DE L'ADR-0029 — deux validations concurrentes.
             *
             * `departDe()` lit la fin la plus tardive des droits datés puis
             * écrit à partir d'elle. Verrouiller la COMMANDE ne suffit pas :
             * deux commandes différentes du même compte ne se disputent aucune
             * ligne, lisent la même fin, et réservent deux fois les mêmes
             * trente jours. Le candidat paie deux mois et en reçoit un.
             *
             * La clé est `(compte, capacité)` — la même que celle du débit,
             * pour qu'un achat et une consommation concurrents se sérialisent
             * eux aussi plutôt que de lire chacun un état que l'autre modifie.
             */
            foreach ($this->capacitesDe($version->capabilities) as $capacite) {
                DB::statement(
                    'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                    ['droit|'.$verrouillee->user_id.'|'.$capacite],
                );
            }

            /*
             * LA CONVERSION, AVANT L'OCTROI ET DANS LA MÊME TRANSACTION.
             *
             * ADR-0033 : « la première activation d'un forfait payant clôt
             * l'essai définitivement ». L'ordre et la transaction ne sont pas
             * des détails d'implémentation, ce sont les deux garanties :
             *
             *   · MÊME TRANSACTION — si l'octroi échoue en dessous, la clôture
             *     est annulée avec lui. Le candidat ne se retrouve jamais sans
             *     droit du tout, ce qui serait le pire des trois états.
             *   · AVANT L'OCTROI — pour que la fenêtre où les deux coexistent
             *     n'existe dans aucun état intermédiaire lisible.
             *
             * `OffreGratuiteService` est résolu ici et non au constructeur :
             * c'est lui qui sait reconnaître un essai, et il dépend déjà de ce
             * service pour poser ses octrois.
             */
            if (self::convertit($verrouillee->method)) {
                app(OffreGratuiteService::class)->clorePourConversion($verrouillee);
            }

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

        $etat = $user->etatCommercial();

        return [
            'capabilities' => $capacites,
            'expires_at' => $echeances->all(),
            /* L'ÉTAT, ET SA SORTIE. Un compte épuisé n'est pas un compte cassé :
             * il lui manque une décision, et l'écran la nomme. Jamais un retour
             * à l'essai — la seule sortie est d'acheter (ADR-0033). */
            'etat' => $etat,
            'etat_label' => __('abonnement.etat_'.$etat),
            'sortie' => $etat === 'epuise' ? __('abonnement.sortie_epuise') : null,
            'droits' => $this->lignesDeDroit($user),
            'quotas' => $this->enveloppesDe($user),
            'pending_orders' => Order::where('user_id', $user->id)->enAttente()->count(),
        ];
    }

    /**
     * Les droits du compte, LIGNE PAR LIGNE, chacune avec sa date propre.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * POURQUOI DES LIGNES ET PAS UN ÉTAT « ABONNÉ »
     *
     * Un compte peut porter, le même jour, un palier gratuit sans terme et un
     * droit transitoire de soixante jours. Rendre « abonné : oui » effacerait
     * la seule chose que le candidat a besoin de savoir : ce qui s'arrête, et
     * quand. Le scénario S-12 le dit pour trois achats croisés — « jamais un
     * état abonné unique » — et le droit transitoire en fait un cas immédiat.
     *
     * UN SEVRAGE S'ANNONCE. Q-17 exige que le droit transitoire apparaisse
     * « avec sa date de fin » : c'est cette ligne, et son libellé le nomme pour
     * ce qu'il est — un accès de transition, pas un abonnement.
     *
     * Les lignes se groupent par (nature, échéance) : deux capacités ouvertes
     * par le même geste, pour la même durée, sont une ligne — pas huit.
     *
     * @return list<array<string, mixed>>
     */
    private function lignesDeDroit(User $user): array
    {
        return AccessGrantRecord::where('user_id', $user->id)
            ->active()
            ->orderBy('capability')
            ->get()
            ->groupBy(fn (AccessGrantRecord $droit): string => $this->natureDe($droit)
                .'|'.($droit->ends_at?->toIso8601String() ?? ''))
            ->map(fn ($droits): array => [
                'source' => $this->natureDe($droits->first()),
                'source_label' => __('abonnement.source_'.$this->natureDe($droits->first())),
                'expires_at' => $droits->first()->ends_at?->toIso8601String(),
                'capabilities' => $droits->pluck('capability')->unique()->sort()->values()->all(),
            ])
            /* CE QUI S'ARRÊTE D'ABORD SE LIT D'ABORD. Le sans-terme ferme la
             * liste : il n'a pas d'échéance à surveiller, et le placer en tête
             * enterrerait la seule ligne qui demande une décision. */
            ->sortBy(fn (array $ligne): string => $ligne['expires_at'] ?? '9999')
            ->values()
            ->all();
    }

    /**
     * Les enveloppes que le compte porte — une par octroi, jamais additionnées.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * POURQUOI UNE LISTE ET PAS UN TOTAL
     *
     * « Deux enveloppes sur des portées distinctes ne sont jamais
     * additionnées » (ADR-0031). Un renouvellement crée une enveloppe neuve
     * (ADR-0027) : les afficher comme un seul nombre effacerait la question qui
     * compte pour le candidat — laquelle se vide en premier. Aujourd'hui il n'y
     * en a qu'une, celle du palier gratuit ; la forme est juste dès maintenant.
     *
     * LE RELIQUAT EST DÉRIVÉ — lot 3B. `quota_value` moins le nombre de lignes
     * de débit rattachées à ce droit. Aucun compteur ne se décrémente : un
     * second dépositaire de la vérité finit par diverger du premier, et c'est
     * alors le faux que le candidat lit.
     *
     * C'EST ICI QUE LE COÛT S'ANNONCE AVANT LE GESTE. Le candidat lit son
     * reliquat sur cette ressource ; le serveur garantit ensuite qu'aucune
     * série ne composera au-delà, et redit le coût dans la réponse qui la
     * sert. Les deux moitiés de la règle du pas 4.
     *
     * LISTE BLANCHE STRICTE : aucun identifiant, aucune origine technique.
     * La NATURE du droit se dit par un mot du produit et son libellé traduit,
     * jamais par le code d'énumération qui la porte en base.
     *
     * @return list<array<string, mixed>>
     */
    private function enveloppesDe(User $user): array
    {
        return AccessGrantRecord::where('user_id', $user->id)
            ->active()
            ->whereNotNull('quota_value')
            ->orderBy('capability')
            ->get()
            ->map(fn (AccessGrantRecord $droit): array => [
                'capability' => $droit->capability,
                'unit' => $droit->quota_unit->value,
                'unit_label' => __('abonnement.unite_'.$droit->quota_unit->value),
                'granted' => $droit->quota_value,
                'remaining' => max(0, $droit->quota_value - QuestionConsumption::query()
                    ->where('access_grant_id', $droit->getKey())->count()),
                'expires_at' => $droit->ends_at?->toIso8601String(),
                'source' => $this->natureDe($droit),
                'source_label' => __('abonnement.source_'.$this->natureDe($droit)),
            ])
            ->values()
            ->all();
    }

    /**
     * Ce que le candidat doit comprendre de l'origine d'un droit : deux mots,
     * pas cinq. `account_level` et `rattrapage` disent la même chose de son
     * point de vue — il ne l'a pas payé.
     */
    private function natureDe(AccessGrantRecord $droit): string
    {
        return match ($droit->origin) {
            'purchase' => 'achetee',
            /* Nommé pour ce qu'il est : un sevrage annoncé n'est ni un cadeau
             * ni un abonnement, et le confondre avec l'un des deux ferait
             * découvrir sa fin le jour où elle tombe (Q-17). */
            'transition' => 'transitoire',
            /* `essai` et non `gratuite` : le mot dit ce que le droit EST — une
             * découverte qui se clôt au premier paiement — plutôt que ce qu'il
             * coûte. Renommé avec l'ADR-0033 ; c'est le même objet. */
            default => 'essai',
        };
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
