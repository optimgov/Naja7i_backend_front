<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\AccessGrant;
use App\Exceptions\CapaciteFermee;
use App\Exceptions\EnveloppeEpuisee;
use App\Exceptions\IdempotencyKeyReused;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttemptResource;
use App\Http\Resources\SimulationReportResource;
use App\Models\Attempt;
use App\Models\Exam;
use App\Services\AttemptService;
use App\Services\DiagnosticComposer;
use App\Services\EnveloppeDeQuestions;
use App\Services\SimulationReport;
use App\Support\ApiError;
use App\Support\MurPayant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * L'examen blanc — le dernier morceau attendu par un candidat de concours.
 *
 * SÉPARÉ DE `ParcoursController`, et pas par confort de rangement. Une
 * simulation obéit à une règle qu'aucune autre tentative ne porte : une
 * ÉCHÉANCE DURE, prise sur la durée officielle de l'épreuve, opposable au
 * candidat. Le diagnostic accepte une durée facultative ; l'entraînement et la
 * révision n'en ont aucune. Mêler les deux surfaces ferait de l'échéance une
 * option parmi d'autres, alors qu'elle est ici la raison d'être.
 *
 * 404, jamais 403 : la tentative d'un autre candidat est introuvable. Le refus
 * du mur payant, lui, est un 403 nommé — il n'y a rien à énumérer, la fonction
 * est au catalogue (voir `MurPayant`).
 */
class SimulationController extends Controller
{
    public function __construct(
        private readonly AttemptService $attempts,
        private readonly DiagnosticComposer $composer,
        private readonly SimulationReport $report,
        private readonly AccessGrant $access,
        private readonly EnveloppeDeQuestions $enveloppe,
    ) {}

    /**
     * Ouvre un examen blanc, ou rend celui déjà en cours.
     *
     * 201 à la création, 200 quand on rend l'existant : un client qui vient de
     * cliquer deux fois doit pouvoir distinguer « j'ai ouvert » de « c'était
     * déjà ouvert » sans comparer des uuid.
     */
    public function start(Request $request, string $examCode): JsonResponse
    {
        $validated = $request->validate([
            'total' => ['sometimes', 'integer', 'between:10,60'],
        ]);

        $exam = Exam::published()->where('code', $examCode)->first();

        if ($exam === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        $user = $request->user();

        /*
         * L'EXAMEN BLANC EST VENDU — lot 3A.9.
         *
         * C'est le chemin le plus coûteux en items du produit : vingt questions
         * publiées, servies d'un coup, sur la durée officielle de l'épreuve.
         *
         * Le refus passe AVANT la durée et avant la banque, et l'ordre n'est pas
         * indifférent : « la durée officielle n'est pas établie » enverrait le
         * candidat chercher un défaut de référentiel là où il n'y a qu'un
         * palier. Le premier obstacle réel est celui qu'on nomme.
         *
         * `show()` ne porte AUCUN mur : un rapport déjà produit est un livrable
         * acquis, et le retirer serait reprendre ce qui a été payé.
         */
        if (! MurPayant::ouvre($this->access, $user, AccessGrant::SIMULATOR_FULL, $exam)) {
            return MurPayant::refus(AccessGrant::SIMULATOR_FULL);
        }

        $total = $validated['total'] ?? config('naja7i.simulation.default_question_count');

        /*
         * LA DURÉE D'ABORD, AVANT MÊME LA BANQUE.
         *
         * Sans durée officielle il n'y a pas d'examen blanc possible — pas
         * « un examen blanc sans chronomètre », pas de repli. Le refus est
         * explicite et porte son propre code : la cause est dans le
         * référentiel de l'épreuve, pas dans la banque de questions, et
         * envoyer le candidat chercher au mauvais endroit serait pire que
         * refuser.
         */
        if ($exam->duration_minutes === null) {
            return ApiError::make(
                'SIMULATION_DURATION_UNKNOWN',
                __('parcours.simulation_duree_inconnue'),
                409,
                ['exam_code' => $exam->code]
            );
        }

        if (! $this->composer->isReady($exam, $user->locale, $total)) {
            return ApiError::make(
                'SIMULATION_NOT_AVAILABLE',
                __('parcours.simulation_indisponible'),
                409,
                ['exam_code' => $exam->code, 'requested' => $total]
            );
        }

        $cle = $request->header('Idempotency-Key') ?: (string) Str::uuid7();

        try {
            $ouverture = $this->attempts->startSimulation($user, $exam, $user->locale, $cle, $total);
        } catch (CapaciteFermee|EnveloppeEpuisee $e) {
            /* AVANT le `RuntimeException` : « examen blanc indisponible »
             * enverrait le candidat chercher un défaut de banque là où il n'y a
             * qu'une enveloppe vide ou un droit fermé. */
            if ($e instanceof CapaciteFermee) {
                return MurPayant::refus($e->capacite);
            }

            return ApiError::make(
                'ENVELOPPE_EPUISEE',
                __('parcours.enveloppe_epuisee'),
                409,
                ['capability' => AccessGrant::QUESTIONS_ANSWER, 'remaining' => $e->reliquat],
            );
        } catch (IdempotencyKeyReused) {
            /* AVANT le `RuntimeException` dont il hérite — même ordre et même
             * raison qu'au diagnostic : sans lui, une clé réutilisée se
             * déguiserait en « examen blanc indisponible » et le client
             * chercherait un problème de banque. */
            return ApiError::make(
                'IDEMPOTENCY_KEY_REUSED',
                __('parcours.cle_idempotence_reutilisee'),
                409,
            );
        } catch (RuntimeException $e) {
            return ApiError::make('SIMULATION_NOT_AVAILABLE', $e->getMessage(), 409);
        }

        $attempt = $ouverture['attempt'];

        return (new AttemptResource($attempt->load(['exam', 'items.question.options', 'items.response'])))
            ->additional(['meta' => [
                'envelope' => $this->enveloppe->annoncePour($user, $attempt, $exam),
            ]])
            ->response()
            ->setStatusCode($ouverture['creee'] ? 201 : 200);
    }

    /**
     * Le rapport post-examen.
     *
     * SERVI APRÈS CLÔTURE UNIQUEMENT, et la clôture peut être provoquée ici :
     * un candidat dont le temps s'est écoulé sans qu'il rende quoi que ce soit
     * arrive sur cette route avec une tentative encore `in_progress`. Le
     * serveur la clôt — c'est lui qui fait foi — puis rend le rapport.
     *
     * Refuser en disant « pas encore soumise » serait absurde : le chronomètre
     * a tranché il y a peut-être une heure, et le candidat n'a aucun moyen de
     * soumettre une épreuve dont l'échéance est passée.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $attempt = Attempt::where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->where('kind', 'simulation')
            ->with('exam')
            ->first();

        /* 404 et non 403 : la simulation d'un autre candidat n'existe pas.
         * Le filtre par `user_id` fait foi, et la règle vise l'énumération. */
        if ($attempt === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        $attempt = $this->attempts->closeIfExpired($attempt);

        if ($attempt->submitted_at === null) {
            /* Toujours en cours et l'échéance n'est pas passée : R06 s'applique
             * entièrement — aucune correction, aucun agrégat, aucun score. */
            return ApiError::make(
                'ATTEMPT_NOT_SUBMITTED',
                __('parcours.correction_avant_soumission'),
                409,
            );
        }

        return (new SimulationReportResource(
            $attempt->load('exam.currentBlueprint'),
            $this->report->build($attempt),
        ))->response();
    }
}
