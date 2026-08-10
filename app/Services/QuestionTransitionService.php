<?php

namespace App\Services;

use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Transitions de statut d'une question — chemin UNIQUE vers la publication.
 *
 * REVUE PAS-5 BLOC-1, le défaut le plus grave du dossier.
 *
 * `QuestionIntegrityChecker` savait refuser une publication, mais **rien ne
 * l'appelait**. `status`, `published_at`, `validator_id` et les drapeaux
 * d'éligibilité étaient assignables en masse : une question sans source
 * vérifiée, dont l'auteur était son propre valideur, pouvait être créée
 * directement en `published` et servie à un candidat.
 *
 * Pire : mes propres tests appelaient le checker directement, au lieu de
 * tenter une mutation interdite. Ils prouvaient que le contrôleur sait dire
 * non, jamais que le système l'empêche. C'est exactement l'écart G10 — une
 * règle détectable mais non imposée — commis dans le lot censé le fermer.
 *
 * Deux mesures, complémentaires :
 *  - les champs de transition sortent de `$fillable` (voir le modèle) ;
 *  - toute transition passe par ce service, qui refuse en transaction.
 */
final class QuestionTransitionService
{
    public function __construct(private readonly QuestionIntegrityChecker $checker) {}

    /** Transitions autorisées. Toute autre est refusée. */
    private const TRANSITIONS = [
        'draft' => ['a_verifier', 'retired'],
        'a_verifier' => ['draft', 'reviewed', 'retired'],
        'reviewed' => ['a_verifier', 'pedagogically_validated', 'retired'],
        'pedagogically_validated' => ['reviewed', 'published', 'retired'],
        'published' => ['retired'],
        'retired' => [],
    ];

    public function submitForReview(Question $question): Question
    {
        return $this->transition($question, 'a_verifier');
    }

    public function markReviewed(Question $question, User $reviewer): Question
    {
        return $this->transition($question, 'reviewed', ['reviewer_id' => $reviewer->id]);
    }

    /**
     * Validation pédagogique. Le valideur ne peut pas être l'auteur —
     * règle permanente METHODE §7.2, désormais appliquée au moment où elle
     * compte, pas seulement constatée après coup.
     */
    public function validate(Question $question, User $validator): Question
    {
        if ($validator->id === $question->author_id) {
            throw new RuntimeException(
                'Le valideur ne peut pas être l\'auteur de la question : une relecture par son auteur n\'est pas une relecture.'
            );
        }

        return $this->transition($question, 'pedagogically_validated', ['validator_id' => $validator->id]);
    }

    /**
     * Publication. Tous les contrôles éditoriaux sont opposés ICI, en
     * transaction, quel que soit le chemin d'appel.
     */
    public function publish(Question $question, bool $forDiagnostic = false, bool $forSimulation = false): Question
    {
        return DB::transaction(function () use ($question, $forDiagnostic, $forSimulation) {
            $question->loadMissing(['options', 'exam.taxonomyProfile', 'node']);

            // Les drapeaux sont posés AVANT le contrôle : on valide l'état
            // final visé, pas l'état de départ.
            $question->eligible_for_diagnostic = $forDiagnostic;
            $question->eligible_for_simulation = $forSimulation;

            $defauts = $this->checker->publicationIssues($question);

            if ($defauts !== []) {
                throw new RuntimeException(
                    "Publication refusée :\n– ".implode("\n– ", $defauts)
                );
            }

            $this->assertTransition($question->status, 'published');

            $question->forceFill([
                'status' => 'published',
                'published_at' => now(),
                'eligible_for_diagnostic' => $forDiagnostic,
                'eligible_for_simulation' => $forSimulation,
            ])->save();

            return $question->fresh();
        });
    }

    /** Retire une version. Seule transition permise depuis `published`. */
    public function retire(Question $question, ?string $reason = null): Question
    {
        return $this->transition($question, 'retired', [
            'retired_at' => now(),
            'eligible_for_diagnostic' => false,
            'eligible_for_simulation' => false,
        ]);
    }

    /**
     * Crée une version corrigée d'une question publiée, et retire l'ancienne.
     *
     * C'est le seul moyen de « modifier » une question publiée — le contenu
     * publié est gelé en base (ADR-0015 §5). Les tentatives passées continuent
     * de pointer vers la version réellement présentée.
     */
    public function createRevision(Question $published, array $attributes): Question
    {
        if ($published->status !== 'published') {
            throw new RuntimeException('Seule une question publiée donne lieu à une révision.');
        }

        return DB::transaction(function () use ($published, $attributes) {
            $nouvelle = Question::create(array_merge([
                'exam_id' => $published->exam_id,
                'competency_node_id' => $published->competency_node_id,
                'locale' => $published->locale,
                'sibling_group' => $published->sibling_group,
                'stem' => $published->stem,
                'explanation' => $published->explanation,
                'remediation_id' => $published->remediation_id,
                'author_id' => $published->author_id,
            ], $attributes));

            $nouvelle->forceFill([
                'version' => $published->version + 1,
                'supersedes_id' => $published->id,
                'status' => 'draft',
            ])->save();

            foreach ($published->options as $option) {
                $nouvelle->options()->create([
                    'position' => $option->position,
                    'content' => $option->content,
                    'is_correct' => $option->is_correct,
                    'rationale' => $option->rationale,
                    'cause' => $option->cause,
                ]);
            }

            return $nouvelle->fresh('options');
        });
    }

    /** @param  array<string, mixed>  $extra */
    private function transition(Question $question, string $vers, array $extra = []): Question
    {
        $this->assertTransition($question->status, $vers);

        $question->forceFill(array_merge(['status' => $vers], $extra))->save();

        return $question->fresh();
    }

    private function assertTransition(string $depuis, string $vers): void
    {
        if (! in_array($vers, self::TRANSITIONS[$depuis] ?? [], true)) {
            throw new RuntimeException(
                "Transition éditoriale interdite : « {$depuis} » vers « {$vers} »."
            );
        }
    }
}
