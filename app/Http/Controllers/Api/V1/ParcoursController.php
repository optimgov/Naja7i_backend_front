<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\AccessGrant;
use App\Exceptions\IdempotencyKeyReused;
use App\Exceptions\TrainingScopeTooNarrow;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttemptResource;
use App\Http\Resources\CorrectionResource;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\QuestionOption;
use App\Services\AttemptService;
use App\Services\CauseRevealService;
use App\Services\DiagnosticComposer;
use App\Services\RemediationPlanner;
use App\Services\TrainingComposer;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Parcours candidat : ouvrir un diagnostic, répondre, soumettre, consulter.
 *
 * Toutes ces routes exigent une session ET un e-mail vérifié (décision du
 * 8 août). Le middleware `verified.api` s'en charge ; aucun contrôle n'est
 * dupliqué ici.
 *
 * Règle transversale : une ressource appartenant à un autre candidat répond
 * 404, jamais 403. Un 403 confirmerait son existence.
 */
class ParcoursController extends Controller
{
    public function __construct(
        private readonly AttemptService $attempts,
        private readonly DiagnosticComposer $composer,
        private readonly AccessGrant $access,
        private readonly CauseRevealService $reveals,
        private readonly RemediationPlanner $planner,
        private readonly TrainingComposer $trainingComposer,
    ) {}

    /** Ouvre un diagnostic, ou rend celui déjà en cours. */
    public function startDiagnostic(Request $request, string $examCode): JsonResponse
    {
        $validated = $request->validate([
            'total' => ['sometimes', 'integer', 'between:5,40'],
        ]);

        $exam = Exam::published()->where('code', $examCode)->first();

        if ($exam === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        $user = $request->user();
        $total = $validated['total'] ?? 10;

        if (! $this->composer->isReady($exam, $user->locale, $total)) {
            return ApiError::make(
                'DIAGNOSTIC_NOT_AVAILABLE',
                __('parcours.diagnostic_indisponible'),
                409,
                ['exam_code' => $exam->code]
            );
        }

        /* La clé d'idempotence vient du client. Absente, on en génère une :
         * l'index unique « un seul diagnostic ouvert par épreuve » sert alors
         * de second filet. */
        $cle = $request->header('Idempotency-Key') ?: (string) Str::uuid7();

        try {
            $attempt = $this->attempts->startDiagnostic($user, $exam, $user->locale, $cle, $total);
        } catch (IdempotencyKeyReused $e) {
            /* AVANT le `RuntimeException` ci-dessous, dont il hérite : sans cet
             * ordre, une clé réutilisée se déguiserait en « diagnostic
             * indisponible » et le client chercherait un problème de banque. */
            return $this->cleReutilisee($e);
        } catch (RuntimeException $e) {
            return ApiError::make('DIAGNOSTIC_NOT_AVAILABLE', $e->getMessage(), 409);
        }

        return (new AttemptResource($attempt->load(['exam', 'items.question.options', 'items.response'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Ouvre une session d'entraînement ciblée, ou rend celle déjà ouverte.
     *
     * Sans `node_uuid`, le périmètre vient de l'ORDONNANCE : c'est ce qui
     * referme la boucle. Le candidat passe son diagnostic, reçoit une liste de
     * priorités, et s'entraîne dessus sans avoir à la retranscrire lui-même.
     */
    public function startTraining(Request $request, string $examCode): JsonResponse
    {
        $validated = $request->validate([
            'node_uuid' => ['sometimes', 'nullable', 'uuid'],
            'total' => ['sometimes', 'integer', 'between:5,40'],
        ]);

        $exam = Exam::published()->where('code', $examCode)->first();

        if ($exam === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        $user = $request->user();
        $total = $validated['total'] ?? 15;

        $noeuds = $this->perimetre($user, $exam, $validated['node_uuid'] ?? null);

        if ($noeuds === []) {
            return ApiError::make(
                'TRAINING_SCOPE_EMPTY',
                __('parcours.entrainement_perimetre_vide'),
                409,
                ['exam_code' => $exam->code]
            );
        }

        $cle = $request->header('Idempotency-Key') ?: (string) Str::uuid7();

        try {
            $session = $this->attempts->startTraining($user, $exam, $noeuds, $user->locale, $cle, $total);
        } catch (IdempotencyKeyReused $e) {
            return $this->cleReutilisee($e);
        } catch (TrainingScopeTooNarrow $e) {
            /* Code DISTINCT de celui du diagnostic : les deux situations se
             * ressemblent mais n'appellent pas la même conduite. */
            return ApiError::make(
                'TRAINING_SCOPE_TOO_NARROW',
                __('parcours.entrainement_perimetre_etroit'),
                409,
                [
                    'exam_code' => $exam->code,
                    'available' => $e->disponibles,
                    'minimum' => TrainingComposer::MINIMUM_UTILE,
                ]
            );
        }

        $attempt = $session['attempt'];

        return (new AttemptResource($attempt->load(['exam', 'items.question.options', 'items.response'])))
            ->additional(['meta' => [
                'requested' => $session['demande'],
                'served' => $attempt->item_count,
                /* On DIT qu'on a servi moins, et pourquoi : la série n'est jamais
                 * complétée hors périmètre, elle est simplement plus courte. */
                'short_of_scope' => $attempt->item_count < $session['demande'],
                'available_in_scope' => $session['disponibles'],
                // Questions déjà réussies, resservies faute de vivier neuf.
                'already_mastered_reused' => $session['resservies'],
            ]])
            ->response()
            ->setStatusCode($session['creee'] ? 201 : 200);
    }

    /**
     * Une clé d'idempotence rejouée sur une AUTRE requête.
     *
     * Refus explicite, jamais une autre tentative. Rendre la préexistante
     * ferait croire au client qu'il a ouvert ce qu'il demandait, et
     * contournerait au passage les gardes d'ouverture — « périmètre trop
     * étroit » et « rien à réviser » ne sont jamais atteintes si le service
     * rend une tentative avant d'y arriver.
     */
    private function cleReutilisee(IdempotencyKeyReused $e): JsonResponse
    {
        return ApiError::make(
            'IDEMPOTENCY_KEY_REUSED',
            __('parcours.cle_idempotence_reutilisee'),
            409,
        );
    }

    /**
     * Périmètre de la session : le nœud demandé, ou les têtes de l'ordonnance.
     *
     * @return list<int>
     */
    private function perimetre($user, Exam $exam, ?string $nodeUuid): array
    {
        if ($nodeUuid !== null) {
            $noeud = CompetencyNode::where('uuid', $nodeUuid)
                ->where('exam_id', $exam->id)
                ->first();

            return $noeud === null ? [] : [$noeud->id];
        }

        /* Les trois premières priorités : assez large pour composer une série,
         * assez étroit pour rester une session ciblée. */
        $uuids = $this->planner->prioritize($user, $exam, 3)->pluck('node_uuid')->all();

        return CompetencyNode::whereIn('uuid', $uuids)
            ->where('exam_id', $exam->id)
            ->pluck('id')
            ->all();
    }

    /**
     * Plafond de l'index. Le reste est ANNONCÉ, jamais tronqué en silence —
     * même règle que la liste de révision, et pour la même raison : un client
     * à qui l'on cache ce qui manque croit avoir tout vu.
     */
    public const PLAFOND_INDEX = 20;

    /**
     * Les tentatives du candidat, la plus récente d'abord.
     *
     * CE QUE CETTE ROUTE RÉPARE. `show()` exige de connaître l'uuid ; sur un
     * appareil neuf, personne ne le connaît. La reprise multi-appareil ne
     * fonctionnait que par un effet de bord — rouvrir un diagnostic rend celui
     * en cours — qui suppose de connaître l'ÉPREUVE, que le frontend gardait
     * dans une trace locale faute de contrat (sa dette D-F15). La béquille
     * existait parce que l'API manquait.
     *
     * LA RESSOURCE NE PORTE PAS LES ITEMS, et ce n'est pas un oubli.
     * `AttemptResource` les expose par `whenLoaded` : ne rien charger suffit à
     * les taire. Une liste n'a besoin ni des énoncés ni des options, et les y
     * mettre rapprocherait la correction d'une surface qui n'a pas à la
     * connaître. Un test l'éprouve sur les octets rendus.
     *
     * `exam` EST chargée, elle : c'est ce qui permet au client de déduire
     * l'épreuve suivie sans profil ni trace locale.
     *
     * Portée : les tentatives d'un autre candidat sont INTROUVABLES, pas
     * interdites — le filtre par `user_id` fait foi, comme dans `find()`.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'in:in_progress,submitted,expired,abandoned'],
            'kind' => ['sometimes', 'in:diagnostic,training,simulation,mirror,review'],
            'exam_code' => ['sometimes', 'string', 'exists:exams,code'],
        ]);

        $requete = Attempt::where('user_id', $request->user()->id)
            ->when(
                isset($validated['status']),
                fn ($q) => $q->where('attempts.status', $validated['status'])
            )
            ->when(
                isset($validated['kind']),
                fn ($q) => $q->where('kind', $validated['kind'])
            )
            ->when(
                isset($validated['exam_code']),
                fn ($q) => $q->whereHas('exam', fn ($e) => $e->where('code', $validated['exam_code']))
            );

        $total = (clone $requete)->count();

        /* `id` en second critère : deux tentatives ouvertes dans la même
         * seconde auraient le même `started_at`, les horodatages étant à la
         * seconde (DET-40), et l'ordre deviendrait celui du hasard. */
        $tentatives = $requete
            ->with('exam')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(self::PLAFOND_INDEX)
            ->get();

        return response()->json([
            'data' => AttemptResource::collection($tentatives)->resolve(),
            'meta' => [
                'total' => $total,
                'served' => $tentatives->count(),
                'pending' => max(0, $total - $tentatives->count()),
                'cap' => self::PLAFOND_INDEX,
            ],
        ]);
    }

    /** État d'une tentative, avec ses questions — jamais leurs réponses. */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $attempt = $this->find($request, $uuid);

        if ($attempt === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        return (new AttemptResource(
            $attempt->load(['exam', 'items.question.options', 'items.response.selectedOption'])
        ))->response();
    }

    /** Enregistre une réponse. Rejouable sans effet de bord. */
    public function answer(Request $request, string $uuid, string $itemUuid): JsonResponse
    {
        $validated = $request->validate([
            'option_uuid' => ['nullable', 'uuid'],
            'confidence' => ['required', 'in:sure,hesitant,guess'],
            'elapsed_ms' => ['sometimes', 'integer', 'min:0', 'max:86400000'],
            'client_reported_at' => ['sometimes', 'date'],
        ]);

        $attempt = $this->find($request, $uuid);

        if ($attempt === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        $item = AttemptItem::where('attempt_id', $attempt->id)->where('uuid', $itemUuid)->first();

        if ($item === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        $option = null;

        if (! empty($validated['option_uuid'])) {
            $option = QuestionOption::where('uuid', $validated['option_uuid'])->first();

            if ($option === null) {
                return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
            }
        }

        try {
            $this->attempts->answer(
                $item,
                $option,
                $validated['confidence'],
                $validated['elapsed_ms'] ?? null,
                $validated['client_reported_at'] ?? null,
            );
        } catch (RuntimeException $e) {
            return ApiError::make('ATTEMPT_CLOSED', $e->getMessage(), 409);
        }

        /* On ne renvoie PAS la correction : le candidat ne doit rien déduire
         * de cette réponse. Seul l'avancement remonte. */
        return response()->json([
            'data' => [
                'item_uuid' => $item->uuid,
                'answered' => true,
                'answered_count' => $attempt->fresh()->answered_count,
                'item_count' => $attempt->item_count,
            ],
        ]);
    }

    /**
     * Clôt la tentative.
     *
     * LE CONTRÔLEUR N'ORCHESTRE PLUS D'EFFETS MÉTIER. Recalcul de maîtrise et
     * planification des rendez-vous vivaient ici, appelés SANS CONDITION après
     * un `submit()` qui rend sans bruit une tentative déjà close — un rejeu de
     * ce POST les rejouait donc, et faisait avancer deux fois le calendrier
     * (audit BLOC-1). Ils sont désormais dans `AttemptService::submit()`,
     * derrière la garde de transition et dans la transaction de clôture.
     *
     * Toute autre voie de soumission — commande de clôture des tentatives
     * expirées, back-office, futur abonné d'événement — en bénéficie du même
     * coup. C'était l'objet de DET-36, refermé par là.
     */
    public function submit(Request $request, string $uuid): JsonResponse
    {
        $attempt = $this->find($request, $uuid);

        if ($attempt === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        return (new AttemptResource($this->attempts->submit($attempt)->load('exam')))->response();
    }

    /**
     * Corrections d'une tentative soumise.
     *
     * Le quota de causes est décompté ICI et une seule fois par réponse :
     * revenir sur sa correction ne recoûte rien.
     */
    public function correction(Request $request, string $uuid): JsonResponse
    {
        $attempt = $this->find($request, $uuid);

        if ($attempt === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        if ($attempt->status === 'in_progress') {
            return ApiError::make(
                'ATTEMPT_NOT_SUBMITTED',
                __('parcours.correction_avant_soumission'),
                409
            );
        }

        $user = $request->user();
        $premium = $this->access->allows($user, AccessGrant::CAUSE_REVEAL);

        $items = $attempt->items()
            ->with(['question.options', 'question.remediation', 'response.selectedOption', 'node'])
            ->get();

        $corrections = $items->map(function (AttemptItem $item) use ($user, $premium) {
            $fausse = $item->response?->is_correct === false;

            /* Une bonne réponse — ou une question restée sans réponse — n'a
             * aucune cause à débloquer. Sinon le service arbitre SEUL : il rend
             * `true` si la cause est visible (déjà révélée, ou unité de quota
             * réservée) et `false` si le quota est épuisé.
             *
             * Le contrôleur ne consulte plus le plafond avant d'agir. C'est
             * exactement ce contrôle en deux temps — lire l'état, puis écrire —
             * qui laissait deux requêtes concurrentes révéler deux causes avec
             * une seule unité restante (REVUE PAS-10 BLOC-3). */
            $visible = ! $fausse || $this->reveals->reveal($user, $item->response, $premium);

            return (new CorrectionResource($item, $visible))->resolve();
        });

        $etat = $this->reveals->status($user, $premium);

        return response()->json([
            'data' => $corrections,
            'meta' => [
                'attempt_uuid' => $attempt->uuid,
                'correct_count' => $attempt->correct_count,
                'item_count' => $attempt->item_count,
                'cause_quota' => [
                    'unlimited' => $premium,
                    'revealed' => $etat['revealed'],
                    'quota' => $etat['quota'],
                ],
            ],
        ]);
    }

    /**
     * Une tentative appartenant à un autre candidat est INTROUVABLE.
     * Le scope tenant filtre déjà ; le filtre par utilisateur ferme le reste.
     */
    private function find(Request $request, string $uuid): ?Attempt
    {
        return Attempt::where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->first();
    }
}
