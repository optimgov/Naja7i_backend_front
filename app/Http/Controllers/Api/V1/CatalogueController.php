<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompetencyNodeResource;
use App\Http\Resources\ExamFamilyResource;
use App\Http\Resources\ExamSessionResource;
use App\Http\Resources\FiliereResource;
use App\Http\Resources\SpecialtyResource;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\ExamFamily;
use App\Models\ExamSession;
use App\Models\Filiere;
use App\Models\Specialty;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Catalogue public — aucune authentification.
 *
 * Ces endpoints alimentent les pages indexables : c'est le levier
 * d'acquisition du plan à 90 jours. Deux conséquences concrètes :
 *
 *  - seul le contenu PUBLIÉ sort. Un brouillon éditorial qui fuirait serait
 *    indexé par Google avant d'être relu ;
 *  - une ressource non publiée répond 404, pas 403. Un 403 confirmerait son
 *    existence et laisserait deviner le catalogue à venir.
 */
class CatalogueController extends Controller
{
    /** Arborescence complète : filières et leurs familles. */
    public function index(Request $request): JsonResponse
    {
        $filieres = Filiere::published()
            ->ordered()
            ->with(['families' => fn ($q) => $q->published()->ordered()])
            ->get();

        return FiliereResource::collection($filieres)->response();
    }

    public function filiere(string $slug): JsonResponse
    {
        $filiere = Filiere::published()
            ->where('slug', $slug)
            ->with(['families' => fn ($q) => $q->published()->ordered()])
            ->first();

        if ($filiere === null) {
            return $this->notFound();
        }

        return (new FiliereResource($filiere))->response();
    }

    /** Page d'une famille de concours : la landing SEO principale. */
    public function family(string $slug): JsonResponse
    {
        $family = ExamFamily::published()
            ->where('slug', $slug)
            ->with([
                'filiere',
                'specialties' => fn ($q) => $q->published()->ordered(),
                'sessions' => fn ($q) => $q->published()->upcoming()->orderBy('year'),
                'taxonomyProfile',
            ])
            ->first();

        if ($family === null) {
            return $this->notFound();
        }

        return (new ExamFamilyResource($family))->response();
    }

    public function specialty(string $familySlug, string $specialtySlug): JsonResponse
    {
        $family = ExamFamily::published()->where('slug', $familySlug)->first();

        if ($family === null) {
            return $this->notFound();
        }

        $specialty = Specialty::published()
            ->where('exam_family_id', $family->id)
            ->where('slug', $specialtySlug)
            ->with('family.filiere')
            ->first();

        if ($specialty === null) {
            return $this->notFound();
        }

        return (new SpecialtyResource($specialty))->response();
    }

    /**
     * Calendrier des sessions, filtrable par filière, famille et année.
     * Chaque date sort avec son statut de confirmation et sa source.
     */
    public function calendar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filiere' => ['sometimes', 'string', 'max:120'],
            'famille' => ['sometimes', 'string', 'max:120'],
            'annee' => ['sometimes', 'integer', 'between:2020,2100'],
        ]);

        $sessions = ExamSession::published()
            ->upcoming()
            ->when(
                isset($validated['famille']),
                fn ($q) => $q->whereHas('family', fn ($f) => $f->where('slug', $validated['famille']))
            )
            ->when(
                isset($validated['filiere']),
                fn ($q) => $q->whereHas(
                    'family.filiere',
                    fn ($f) => $f->where('slug', $validated['filiere'])
                )
            )
            ->when(isset($validated['annee']), fn ($q) => $q->where('year', $validated['annee']))
            ->with('family')
            ->orderByRaw('written_exam_on NULLS LAST')
            ->orderBy('year')
            ->get();

        return ExamSessionResource::collection($sessions)->response();
    }

    /**
     * Référentiel de compétences d'une ÉPREUVE, en arbre.
     *
     * PAS-4.1 — C'est désormais l'entrée de référence. Chaque épreuve du CRMEF
     * a sa propre matrice de domaines et de poids (ADR-0014) : les fusionner
     * sous la famille aurait produit un simulateur sans rapport avec le
     * concours réel, puisque les trois épreuves se passent séparément.
     */
    public function examCompetencies(string $code): JsonResponse
    {
        $exam = Exam::published()
            ->where('code', $code)
            ->with('taxonomyProfile')
            ->first();

        if ($exam === null) {
            return $this->notFound();
        }

        $nodes = CompetencyNode::where('exam_id', $exam->id)
            ->with('source')
            ->orderBy('depth')
            ->orderBy('position')
            ->get();

        $profile = $exam->taxonomyProfile;

        return response()->json([
            'data' => CompetencyNodeResource::collection($this->buildTree($nodes))->resolve(),
            'meta' => [
                'exam' => [
                    'code' => $exam->code,
                    'name' => $exam->localized('name'),
                    'coefficient' => $exam->coefficient,
                    'duration_minutes' => $exam->duration_minutes,
                    'provenance' => $exam->provenance,
                ],
                'levels' => collect($profile?->levels ?? [])
                    ->map(fn ($l, $i) => ['depth' => $i, 'name' => $profile->levelName($i)])
                    ->values(),
                'node_count' => $nodes->count(),
            ],
        ]);
    }

    /**
     * Référentiel d'une FAMILLE. Conservé pour les familles qui gardent une
     * taxonomie propre ; répond 404 dès qu'une famille n'en a plus, plutôt que
     * de renvoyer un arbre vide qui laisserait croire à un programme inexistant.
     */
    public function competencies(string $familySlug): JsonResponse
    {
        $family = ExamFamily::published()
            ->where('slug', $familySlug)
            ->with('taxonomyProfile')
            ->first();

        if ($family === null || $family->taxonomyProfile === null) {
            return $this->notFound();
        }

        // Un seul chargement, puis reconstruction de l'arbre en mémoire :
        // la profondeur étant variable, un eager loading imbriqué en dur
        // ne conviendrait pas.
        $nodes = CompetencyNode::where('exam_family_id', $family->id)
            ->with('source')
            ->orderBy('depth')
            ->orderBy('position')
            ->get();

        $racines = $this->buildTree($nodes);

        return response()->json([
            'data' => CompetencyNodeResource::collection($racines)->resolve(),
            'meta' => [
                'family' => ['slug' => $family->slug, 'name' => $family->localized('name')],
                'levels' => collect($family->taxonomyProfile?->levels ?? [])
                    ->map(fn ($l, $i) => [
                        'depth' => $i,
                        'name' => $family->taxonomyProfile->levelName($i),
                    ])->values(),
                'node_count' => $nodes->count(),
            ],
        ]);
    }

    /** @param  Collection<int, CompetencyNode>  $nodes */
    private function buildTree($nodes)
    {
        $parIdentifiant = $nodes->keyBy('id');

        foreach ($nodes as $node) {
            $node->setRelation('children', collect());
        }

        foreach ($nodes as $node) {
            if ($node->parent_id !== null && $parIdentifiant->has($node->parent_id)) {
                $parIdentifiant[$node->parent_id]->children->push($node);
            }
        }

        return $nodes->whereNull('parent_id')->values();
    }

    private function notFound(): JsonResponse
    {
        return ApiError::make('RESOURCE_NOT_FOUND', __('errors.not_found'), 404);
    }
}
