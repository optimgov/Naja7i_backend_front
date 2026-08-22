<?php

namespace App\Services;

use App\Models\AccessGrantRecord;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Tenancy\TenantBypass;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * L'attribution du palier gratuit — ADR-0025, « le porteur du gratuit ».
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE SERVICE N'OUVRE AUCUN DROIT LUI-MÊME
 *
 * Il choisit l'offre, vérifie que le compte ne la porte pas déjà, et appelle
 * `AbonnementService::octroyerLesDroits()` — le même chemin que l'honoration
 * d'une commande. C'est la condition posée par l'ADR : « son attribution crée
 * des droits et une enveloppe de consommation explicites PAR LA CHAÎNE
 * NORMALE ». Un second circuit d'octroi produirait un gratuit qui vieillirait
 * autrement que le payant.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * L'IDEMPOTENCE SE JUGE SUR TOUTES LES VERSIONS, PAS SUR LA COURANTE
 *
 * Un compte inscrit sous la version 1 ne doit pas recevoir la version 2 parce
 * qu'un rattrapage passe après une modification du quota : « les comptes
 * existants conservent leurs droits et leur enveloppe ; leur migration est un
 * geste administratif explicite ». La garde regarde donc si le compte porte un
 * droit issu de N'IMPORTE QUELLE version de l'offre gratuite — pas seulement
 * de celle du jour.
 *
 * L'index unique `(user_id, capability, origin_reference)` reste la dernière
 * serrure : deux attributions concurrentes du même compte ne peuvent pas
 * produire deux droits, même si les deux passent la lecture.
 */
final class OffreGratuiteService
{
    /** Ce que le compte reçoit du seul fait d'exister. */
    public const ORIGINE_INSCRIPTION = 'account_level';

    /** Ce qu'une commande d'administration pose après coup, des mois plus tard. */
    public const ORIGINE_RATTRAPAGE = 'rattrapage';

    /** Ce que porte la note d'un essai clos par une conversion (ADR-0033). */
    public const MARQUE_CONVERSION = 'clos par conversion, commande';

    public function __construct(
        private readonly AbonnementService $abonnements,
        private readonly PlanVersionService $versions,
    ) {}

    /** L'offre auto-attribuée, s'il y en a une. L'index unique garantit l'unicité. */
    public function porteuse(): ?Plan
    {
        return Plan::query()->autoGranted()->first();
    }

    /**
     * Attribue le palier gratuit à un compte. Rend `false` s'il l'avait déjà.
     *
     * AUCUNE COMMANDE N'EST CRÉÉE. Il n'y a rien à payer, rien à valider, et
     * rien qu'un agrégat de vente doive compter (ADR-0028, C-05).
     */
    public function attribuer(User $user, string $origine = self::ORIGINE_INSCRIPTION): bool
    {
        $offre = $this->porteuse();

        if ($offre === null) {
            /* Aucun porteur du gratuit : ce n'est pas une erreur, c'est une
             * plateforme qui ne distribue rien. L'inscription doit aboutir. */
            return false;
        }

        return DB::transaction(function () use ($user, $offre, $origine): bool {
            /* LA GARDE EST DOUBLE — ADR-0033, règle 10. « Ni essai déjà reçu,
             * ni conversion déjà survenue » : sans le second terme, un compte
             * qui a payé sans être jamais passé par l'essai en recevrait un
             * neuf au premier rattrapage. Les deux se lisent sur des faits
             * DURABLES, jamais sur un droit actif — un forfait finit toujours
             * par expirer, et l'éligibilité ne doit pas revenir avec. */
            if ($this->porteDejaLeGratuit($user, $offre) || $this->aDejaConverti($user)) {
                return false;
            }

            $version = $this->versions->current($offre);

            try {
                $this->abonnements->octroyerLesDroits(
                    $user->id,
                    $version,
                    $origine,
                    $version->uuid,
                    "Offre gratuite {$offre->code} v{$version->version}",
                );
            } catch (UniqueConstraintViolationException) {
                /* Deux attributions concurrentes : l'autre a gagné, et la base
                 * l'a dit. Rejouer n'est pas une erreur — c'est le rejeu. */
                return false;
            }

            return true;
        });
    }

    /**
     * CLORE L'ESSAI PARCE QU'UN FORFAIT PAYANT S'OUVRE — ADR-0033.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * UNE ÉCRITURE DE DATE SUFFIT À TOUT FERMER
     *
     * `DatabaseAccessGrant::allows()` est un `exists()` sur les octrois ACTIFS,
     * sans notion de priorité ni de catégorie : un essai non clos continuerait
     * d'ouvrir `questions.answer` sous un abonnement payant, et la consommation
     * devrait choisir laquelle des deux enveloppes débiter. Poser `ends_at`
     * suffit — `scopeActive()` l'exclut, la résolution ne le voit plus, il ne
     * reste qu'une enveloppe. La simplification s'obtient par une date, pas par
     * une refonte de la résolution.
     *
     * LA LIGNE N'EST JAMAIS SUPPRIMÉE. Elle devient la preuve durable de la
     * conversion, et porte la référence de la commande qui l'a close : c'est ce
     * qui interdit de recréer l'éligibilité douze mois plus tard.
     *
     * REJOUER NE FAIT RIEN : un essai déjà clos n'est plus actif, donc plus
     * sélectionné.
     *
     * @return int le nombre d'octrois d'essai clos
     */
    public function clorePourConversion(Order $commande): int
    {
        $offre = $this->porteuse();

        if ($offre === null) {
            return 0;
        }

        $maintenant = now();

        $essais = AccessGrantRecord::query()
            ->where('user_id', $commande->user_id)
            ->whereIn('origin_reference', $offre->versions()->selectRaw('uuid::text'))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $maintenant))
            ->get();

        foreach ($essais as $essai) {
            $essai->forceFill([
                'ends_at' => $maintenant,
                /* La trace vit sur les colonnes existantes : `note` porte déjà
                 * l'origine lisible de l'octroi, et une colonne `converted_at`
                 * serait une seconde source de vérité pour un fait que la ligne
                 * close dit déjà. */
                'note' => trim(($essai->note ?? '').' — '.self::MARQUE_CONVERSION.' '.$commande->uuid),
            ])->save();
        }

        return $essais->count();
    }

    /**
     * LE COMPTE A-T-IL DÉJÀ CONVERTI ? — la preuve durable de l'ADR-0033.
     *
     * Deux faits, et aucun n'est un droit actif :
     *
     *   1. un octroi d'essai CLOS par une conversion — la ligne porte la
     *      référence de la commande, et un octroi ne se supprime jamais ;
     *   2. une commande HONORÉE dont la méthode convertit — pour les comptes
     *      qui ont payé sans être jamais passés par l'essai, ceux d'avant
     *      l'offre gratuite.
     *
     * LE SECOND FAIT SE LIT HORS SCOPE TENANT, DÉLIBÉRÉMENT. Une commande est
     * isolée par organisme parce que c'est une activité ; « cette personne a
     * déjà payé une fois » est un fait de la PERSONNE, et son compte la suit
     * s'il quitte un organisme (DET-24). Lire sous le seul tenant courant
     * rendrait l'éligibilité à qui a payé ailleurs.
     */
    public function aDejaConverti(User $user): bool
    {
        $essaiClos = AccessGrantRecord::query()
            ->where('user_id', $user->id)
            ->where('note', 'like', '%'.self::MARQUE_CONVERSION.'%')
            ->exists();

        if ($essaiClos) {
            return true;
        }

        return TenantBypass::run(
            'Verifier si ce compte a deja converti : la premiere conversion est un fait de la '
            .'personne, pas une activite d organisme, et le compte suit la personne',
            fn (): bool => Order::query()
                ->where('user_id', $user->id)
                ->where('status', 'honoree')
                ->whereIn('method', AbonnementService::MOYENS_QUI_CONVERTISSENT)
                ->exists(),
        );
    }

    /** Le compte porte-t-il déjà un droit issu d'une version de l'offre gratuite ? */
    public function porteDejaLeGratuit(User $user, ?Plan $offre = null): bool
    {
        $offre ??= $this->porteuse();

        if ($offre === null) {
            return false;
        }

        return AccessGrantRecord::query()
            ->where('user_id', $user->id)
            /* `origin_reference` est une chaîne, `plan_versions.uuid` un `uuid` :
             * la sous-requête cast, comme partout ailleurs. */
            ->whereIn('origin_reference', $offre->versions()->selectRaw('uuid::text'))
            ->exists();
    }
}
