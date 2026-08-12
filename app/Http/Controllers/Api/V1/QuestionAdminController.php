<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuestionAdminResource;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\Remediation;
use App\Models\Source;
use App\Services\QuestionAuthoringService;
use App\Services\QuestionIntegrityChecker;
use App\Services\QuestionTransitionService;
use App\Support\ApiError;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Première action réellement protégée par une permission fine.
 *
 * REVUE PAS-9 BLOC-2 : sans au moins un endpoint consommant le résolveur, les
 * dix-neuf permissions et leur trigger restaient verts sans effet sur
 * l'autorisation réelle. Cet endpoint referme l'écart et sert de gabarit aux
 * actions du back-office.
 *
 * L'autorisation est portée par le middleware `permission:questions.publish` —
 * pas par un contrôle dans la méthode, pour que le refus survienne avant toute
 * logique métier.
 */
class QuestionAdminController extends Controller
{
    /**
     * Plafond de la liste éditoriale. Le reste est ANNONCÉ dans `meta`, jamais
     * tronqué en silence — même règle que l'index des tentatives et la liste de
     * révision.
     */
    public const PLAFOND_LISTE = 50;

    public function __construct(
        private readonly QuestionTransitionService $transitions,
        private readonly QuestionAuthoringService $authoring,
        private readonly QuestionIntegrityChecker $checker,
    ) {}

    /**
     * Rédiger une question. Elle naît BROUILLON, quoi qu'on demande.
     *
     * Les champs de transition sont hors de `$fillable` depuis la revue
     * PAS-10 : aucune charge utile ne peut faire naître une question publiée.
     * Ce contrôleur n'a donc pas à s'en défendre, et ne le fait pas.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->reglesDeRedaction());

        $exam = Exam::where('code', $validated['exam_code'])->first();
        $noeud = CompetencyNode::where('uuid', $validated['competency_node_uuid'])->first();

        if ($exam === null || $noeud === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        $source = isset($validated['source_code'])
            ? Source::where('code', $validated['source_code'])->first()
            : null;

        if (isset($validated['source_code']) && $source === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        $remediation = isset($validated['remediation_uuid'])
            ? Remediation::where('uuid', $validated['remediation_uuid'])->first()
            : null;

        $question = $this->authoring->rediger(
            $request->user(),
            [
                'exam_id' => $exam->id,
                'competency_node_id' => $noeud->id,
                'locale' => $validated['locale'],
                'stem' => $validated['stem'],
                'explanation' => $validated['explanation'],
                'kind' => $validated['kind'] ?? 'qcm_single',
                'difficulty' => $validated['difficulty'] ?? null,
                'remediation_id' => $remediation?->id,
            ],
            $validated['options'],
            $source,
            $validated['source_locator'] ?? null,
        );

        return $this->rendre($question, 201);
    }

    /**
     * Amender un brouillon.
     *
     * Le gel du contenu publié est tenu EN BASE par trigger, quel que soit le
     * chemin d'écriture. Le service le devance pour rendre un refus lisible ;
     * la garantie, elle, ne dépend pas de ce contrôleur.
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $regles = collect($this->reglesDeRedaction())
            ->map(fn (array $r) => array_map(
                fn ($contrainte) => $contrainte === 'required' ? 'sometimes' : $contrainte,
                $r
            ))
            ->all();

        $validated = $request->validate($regles);

        $question = Question::where('uuid', $uuid)->first();

        if ($question === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        $attributs = array_filter([
            'stem' => $validated['stem'] ?? null,
            'explanation' => $validated['explanation'] ?? null,
            'difficulty' => $validated['difficulty'] ?? null,
            'kind' => $validated['kind'] ?? null,
        ], fn ($v) => $v !== null);

        if (isset($validated['competency_node_uuid'])) {
            $noeud = CompetencyNode::where('uuid', $validated['competency_node_uuid'])->first();

            if ($noeud === null) {
                return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
            }

            $attributs['competency_node_id'] = $noeud->id;
        }

        try {
            $amendee = $this->authoring->amender($question, $attributs, $validated['options'] ?? null);
        } catch (QueryException $e) {
            /*
             * LE TRIGGER A PARLÉ, ET SON MESSAGE NE SORT JAMAIS TEL QUEL.
             *
             * `QueryException` hérite de `RuntimeException` : sans ce `catch`
             * placé AVANT, elle tombait dans celui du dessous et le SQL complet
             * — requête et valeurs liées — partait au client sous couvert d'un
             * message métier. Découvert en vérifiant par mutation que la garde
             * applicative discriminait : elle ne discriminait pas, parce que la
             * base refusait déjà — mais en révélant ses entrailles.
             *
             * `P0001` est le SQLSTATE d'un `RAISE EXCEPTION` PL/pgSQL, donc
             * d'une garde éditoriale de ce dépôt. Tout autre code est une vraie
             * panne et se relance : on ne déguise pas une erreur de base en
             * refus métier.
             */
            if (($e->errorInfo[0] ?? null) !== 'P0001') {
                throw $e;
            }

            return ApiError::make('QUESTION_FROZEN', __('parcours.question_gelee'), 409);
        } catch (RuntimeException $e) {
            return ApiError::make('QUESTION_FROZEN', $e->getMessage(), 409);
        }

        return $this->rendre($amendee);
    }

    /** Lister et filtrer la banque. Bornée, le reste annoncé. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'in:'.implode(',', Question::STATUSES)],
            'locale' => ['sometimes', 'in:fr,ar'],
            'competency' => ['sometimes', 'string', 'max:64'],
            'author' => ['sometimes', 'string', 'max:64'],
        ]);

        $requete = Question::query()
            ->when(isset($validated['status']), fn ($q) => $q->where('questions.status', $validated['status']))
            ->when(isset($validated['locale']), fn ($q) => $q->where('questions.locale', $validated['locale']))
            ->when(
                isset($validated['competency']),
                /* Par CODE de compétence : c'est ce que le plan de rédaction
                 * (`admin/banque/couverture`) désigne, et le rédacteur passe de
                 * l'un à l'autre sans traduction. */
                fn ($q) => $q->whereHas('node', fn ($n) => $n->where('code', $validated['competency']))
            )
            ->when(
                isset($validated['author']),
                fn ($q) => $q->whereHas('author', fn ($a) => $a->where('uuid', $validated['author']))
            );

        return $this->liste($requete);
    }

    /**
     * La file de RELECTURE : ce qui attend un relecteur, le plus ancien d'abord.
     *
     * Le plus ancien d'abord, et non le plus récent : une question soumise il y
     * a trois semaines a déjà attendu, et la servir en dernier ferait d'une
     * file une pile.
     */
    public function aRelire(Request $request): JsonResponse
    {
        return $this->liste(
            Question::where('questions.status', 'a_verifier')->orderBy('questions.updated_at'),
            plusAncienDAbord: true,
        );
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $question = Question::where('uuid', $uuid)->first();

        if ($question === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        return $this->rendre($question);
    }

    /** @return array<string, mixed> */
    private function reglesDeRedaction(): array
    {
        return [
            'exam_code' => ['required', 'string', 'max:64'],
            'competency_node_uuid' => ['required', 'uuid'],
            'locale' => ['required', 'in:fr,ar'],
            'stem' => ['required', 'string', 'min:3'],
            'explanation' => ['required', 'string', 'min:3'],
            'kind' => ['sometimes', 'in:qcm_single,qcm_multiple,true_false'],
            'difficulty' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'remediation_uuid' => ['sometimes', 'uuid'],
            'source_code' => ['sometimes', 'string', 'max:64'],
            'source_locator' => ['sometimes', 'nullable', 'string', 'max:255'],
            'options' => ['required', 'array', 'between:2,6'],
            'options.*.content' => ['required', 'string'],
            'options.*.is_correct' => ['required', 'boolean'],
            'options.*.rationale' => ['required', 'string', 'min:3'],
            /* La cause n'est pas exigée à la rédaction : elle l'est à la
             * PUBLICATION pour diagnostic, et `QuestionIntegrityChecker` le dit
             * déjà (fiche F03 v1.1). L'exiger ici empêcherait d'enregistrer un
             * brouillon en cours d'écriture. */
            'options.*.cause' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    private function rendre(Question $question, int $statut = 200): JsonResponse
    {
        $question->loadMissing(['options', 'node', 'exam', 'author', 'contentSources']);

        return (new QuestionAdminResource($question))
            ->additional(['meta' => [
                /* CE QUI BLOQUE LA PUBLICATION, à chaque lecture. Le rédacteur
                 * n'a pas à tenter une publication pour apprendre ce qui
                 * manque : les mêmes contrôles que `publish()` opposera, rendus
                 * comme une liste de blocages. Aucune règle n'est réécrite
                 * ici — c'est `QuestionIntegrityChecker` qui répond. */
                'publication_blockers' => $this->checker->publicationIssues($question),
                'diagnostic_blockers' => $this->checker->diagnosticIssues($question),
            ]])
            ->response()
            ->setStatusCode($statut);
    }

    private function liste($requete, bool $plusAncienDAbord = false): JsonResponse
    {
        $total = (clone $requete)->count();

        $questions = $requete
            ->with(['node', 'exam', 'author'])
            ->when(! $plusAncienDAbord, fn ($q) => $q->orderByDesc('questions.updated_at'))
            ->orderByDesc('questions.id')
            ->limit(self::PLAFOND_LISTE)
            ->get();

        return response()->json([
            'data' => QuestionAdminResource::collection($questions)->resolve(),
            'meta' => [
                'total' => $total,
                'served' => $questions->count(),
                'pending' => max(0, $total - $questions->count()),
                'cap' => self::PLAFOND_LISTE,
            ],
        ]);
    }

    public function publish(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'for_diagnostic' => ['sometimes', 'boolean'],
            'for_simulation' => ['sometimes', 'boolean'],
        ]);

        $question = Question::where('uuid', $uuid)->first();

        if ($question === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        try {
            $publiee = $this->transitions->publish(
                $question,
                forDiagnostic: (bool) ($validated['for_diagnostic'] ?? false),
                forSimulation: (bool) ($validated['for_simulation'] ?? false),
            );
        } catch (RuntimeException $e) {
            return ApiError::make('QUESTION_NOT_PUBLISHABLE', $e->getMessage(), 422);
        }

        return response()->json([
            'data' => [
                'uuid' => $publiee->uuid,
                'status' => $publiee->status,
                'published_at' => $publiee->published_at?->toIso8601String(),
                'eligible_for_diagnostic' => $publiee->eligible_for_diagnostic,
                'eligible_for_simulation' => $publiee->eligible_for_simulation,
            ],
        ]);
    }

    /*
     * ================================================================
     * Les transitions intermédiaires de la chaîne — PAS-33.
     *
     * `publish` et `retire` avaient leur route depuis le PAS-11 ; les trois
     * étapes qui les précèdent n'existaient qu'en Filament. Un appelant
     * programmatique — le semis de la banque de recette, en particulier — ne
     * pouvait donc pas mener une question du brouillon à la publication sans
     * passer sous l'API.
     *
     * AUCUNE RÈGLE NOUVELLE N'EST ÉCRITE ICI. Ces trois méthodes appellent
     * `QuestionTransitionService` et traduisent son refus en réponse HTTP.
     * L'autorisation est portée par le middleware déclaré sur la route, comme
     * pour les deux existantes : aucun `if` d'autorisation dans le corps.
     * ================================================================
     */

    /** Le rédacteur envoie son brouillon à la relecture. */
    public function submit(Request $request, string $uuid): JsonResponse
    {
        return $this->transiter($uuid, fn (Question $q) => $this->transitions->submitForReview($q));
    }

    /** Le relecteur atteste avoir relu. Son identité est enregistrée. */
    public function review(Request $request, string $uuid): JsonResponse
    {
        return $this->transiter($uuid, fn (Question $q) => $this->transitions->markReviewed($q, $request->user()));
    }

    /**
     * Validation pédagogique — et JAMAIS par l'auteur.
     *
     * La règle vit dans le service (METHODE §7.2) et y reste. Elle s'oppose
     * donc à cette route comme elle s'oppose au bouton du back-office : ni
     * l'une ni l'autre ne la porte, toutes deux la subissent.
     */
    public function validatePedagogy(Request $request, string $uuid): JsonResponse
    {
        return $this->transiter($uuid, fn (Question $q) => $this->transitions->validate($q, $request->user()));
    }

    /**
     * Le squelette commun : trouver, tenter, traduire le refus.
     *
     * UN SEUL CODE D'ERREUR POUR DEUX REFUS DIFFÉRENTS, et c'est délibéré. Le
     * service lève un `RuntimeException` aussi bien pour « transition interdite
     * depuis cet état » que pour « le valideur est l'auteur ». Les distinguer
     * demanderait de lire le TEXTE du message, ce qui casserait le jour où sa
     * formulation change — un couplage plus fragile que l'information gagnée.
     * Le message, lui, est rendu tel quel : il dit lequel des deux s'applique.
     *
     * @param  callable(Question): Question  $acte
     */
    private function transiter(string $uuid, callable $acte): JsonResponse
    {
        $question = Question::where('uuid', $uuid)->first();

        if ($question === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        try {
            $apres = $acte($question);
        } catch (RuntimeException $e) {
            return ApiError::make('QUESTION_TRANSITION_REFUSED', $e->getMessage(), 422);
        }

        /* La ressource complète, et non le seul statut : une transition change
         * ce qui bloque la SUIVANTE, et `rendre()` porte déjà les deux listes
         * de blocages. Un appelant qui enchaîne la chaîne — c'est le cas du
         * semis de recette — s'épargne une lecture entre chaque étape. */
        return $this->rendre($apres);
    }

    public function retire(Request $request, string $uuid): JsonResponse
    {
        $question = Question::where('uuid', $uuid)->first();

        if ($question === null) {
            return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
        }

        try {
            $retiree = $this->transitions->retire($question);
        } catch (RuntimeException $e) {
            return ApiError::make('QUESTION_NOT_RETIRABLE', $e->getMessage(), 422);
        }

        return response()->json(['data' => ['uuid' => $retiree->uuid, 'status' => $retiree->status]]);
    }
}
