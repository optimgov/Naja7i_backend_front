<?php

namespace App\Services;

use App\Models\AccessGrantRecord;
use App\Models\Plan;
use App\Models\User;
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
            if ($this->porteDejaLeGratuit($user, $offre)) {
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
