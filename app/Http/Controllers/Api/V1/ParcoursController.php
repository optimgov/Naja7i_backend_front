<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\AccessGrant;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttemptResource;
use App\Http\Resources\CorrectionResource;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\Exam;
use App\Models\QuestionOption;
use App\Services\AttemptService;
use App\Services\DiagnosticComposer;
use App\Services\MasteryCalculator;
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
        private readonly MasteryCalculator $mastery,
        private readonly AccessGrant $access,
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
        } catch (RuntimeException $e) {
            return ApiError::make('DIAGNOSTIC_NOT_AVAILABLE', $e->getMessage(), 409);
        }

        return (new AttemptResource($attempt->load(['exam', 'items.question.options', 'items.response'])))
            ->response()
            ->setStatusCode(201);
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

    /** Clôt la tentative, fige les corrections et recalcule la maîtrise. */
    public function submit(Request $request, string $uuid): JsonResponse
    {
        $attempt = $this->find($request, $uuid);

        if ($attempt === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        $clos = $this->attempts->submit($attempt);

        if ($clos->exam !== null) {
            $this->mastery->recomputeForExam($request->user(), $clos->exam);
        }

        return (new AttemptResource($clos->load('exam')))->response();
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
            $response = $item->response;
            $fausse = $response?->is_correct === false;

            $visible = false;

            if (! $fausse) {
                $visible = true;   // rien à débloquer sur une bonne réponse
            } elseif ($response->cause_revealed) {
                $visible = true;   // déjà décomptée
            } else {
                $etat = $this->attempts->canRevealCause($user, $premium);

                if ($etat['allowed']) {
                    /* Le service rend `true` quand il vient de consommer une
                     * unité, `false` quand une consultation concurrente l'avait
                     * déjà consommée pour cette réponse. Dans les deux cas la
                     * cause est payée, donc visible — mais elle n'est décomptée
                     * qu'une fois, et c'est l'UPDATE conditionnel en base qui
                     * l'arbitre, jamais ce contrôleur. */
                    $this->attempts->markCauseRevealed($user, $response);
                    $visible = true;
                }
            }

            return (new CorrectionResource($item, $visible))->resolve();
        });

        $etat = $this->attempts->canRevealCause($user, $premium);

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
