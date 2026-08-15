<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\AccessGrant;
use App\Exceptions\PaiementRefuse;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\PlanResource;
use App\Models\Order;
use App\Models\Plan;
use App\Services\AbonnementService;
use App\Services\Paiement\CouponGateway;
use App\Services\Paiement\SimulatedGateway;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * La surface d'abonnement du candidat.
 *
 * ELLE NE DÉCIDE D'AUCUN DROIT. Elle ouvre des commandes ; les droits sont
 * posés par `AbonnementService` et lus par `AccessGrant`. Le mur payant, lui,
 * ne connaît ni ce contrôleur ni les commandes — c'est ce qui permet de changer
 * de moyen de paiement sans y toucher.
 *
 * 404, jamais 403 : la commande d'un autre candidat est introuvable.
 */
class AbonnementController extends Controller
{
    public function __construct(
        private readonly AbonnementService $abonnements,
        private readonly AccessGrant $droits,
        private readonly CouponGateway $coupons,
    ) {}

    /**
     * Les offres — PUBLIQUE, sans session.
     *
     * La surface commerciale vit dans la zone publique : un visiteur doit
     * pouvoir lire les tarifs avant de créer un compte, et la page doit être
     * indexable. Exiger une session ici reviendrait à cacher le prix derrière
     * une inscription, ce qu'aucun candidat ne pardonne.
     */
    public function plans(): JsonResponse
    {
        $plans = Plan::active()->ordered()->get();

        return response()->json(['data' => PlanResource::collection($plans)->resolve()]);
    }

    /** L'état courant : ce dont le candidat DISPOSE, pas ce qu'il a acheté. */
    public function etat(Request $request): JsonResponse
    {
        $etat = $this->abonnements->etat($request->user(), $this->droits);

        return response()->json(['data' => $etat]);
    }

    public function commandes(Request $request): JsonResponse
    {
        $commandes = Order::where('user_id', $request->user()->id)
            ->with('plan')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => OrderResource::collection($commandes)->resolve()]);
    }

    /**
     * Saisir un coupon → une commande EN ATTENTE.
     *
     * 201 et non 200 : quelque chose a été créé, et le candidat doit
     * comprendre que sa demande est enregistrée — pas honorée. L'écran dit
     * « en cours de validation », et c'est la vérité.
     */
    public function coupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $cle = $request->header('Idempotency-Key') ?: (string) Str::uuid7();

        try {
            $commande = $this->coupons->ouvrir($request->user(), $validated, $cle);
        } catch (PaiementRefuse $e) {
            /*
             * LE MOTIF EST NOMMÉ, et c'est ce qui distingue « votre coupon a
             * expiré » de « ce code n'existe pas ». Un refus générique
             * laisserait le candidat ressaisir indéfiniment un code périmé.
             *
             * Ce qui n'est PAS dit : si un code inconnu ressemble à un code
             * valide. On ne confirme jamais l'existence d'un coupon qu'on
             * refuse pour une autre raison — ce serait un oracle pour qui
             * cherche à en deviner un.
             */
            return ApiError::make(
                'COUPON_REFUSE',
                __("abonnement.coupon_{$e->motif}"),
                422,
                ['motif' => $e->motif],
            );
        }

        return (new OrderResource($commande->load('plan')))->response()->setStatusCode(201);
    }

    /**
     * Le paiement SIMULÉ — hors production uniquement.
     *
     * La route n'est pas déclarée en production (voir `routes/api.php`), et
     * `SimulatedGateway` refuse de s'instancier là-bas. Deux serrures pour la
     * même règle : une route absente protège une porte, un constructeur qui
     * lève protège la maison.
     *
     * Le service est résolu ICI et non par le constructeur du contrôleur :
     * autrement, la simple existence de cette classe ferait échouer TOUTE
     * requête en production, y compris la lecture des tarifs.
     */
    public function simule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_code' => ['required', 'string', 'max:64'],
        ]);

        $cle = $request->header('Idempotency-Key') ?: (string) Str::uuid7();

        try {
            $commande = app(SimulatedGateway::class)
                ->ouvrir($request->user(), $validated, $cle);
        } catch (PaiementRefuse $e) {
            return ApiError::make(
                'PAIEMENT_REFUSE',
                __("abonnement.coupon_{$e->motif}"),
                422,
                ['motif' => $e->motif],
            );
        }

        return (new OrderResource($commande->load('plan')))->response()->setStatusCode(201);
    }
}
