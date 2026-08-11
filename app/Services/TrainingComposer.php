<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\Question;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Composition d'une série d'ENTRAÎNEMENT.
 *
 * Classe distincte de `DiagnosticComposer`, et non une option de celui-ci : les
 * deux principes de sélection sont opposés.
 *
 *  - Le diagnostic reproduit les POIDS OFFICIELS de l'épreuve. Il ne doit pas
 *    flatter un candidat fort sur un domaine mineur.
 *  - L'entraînement vise DÉLIBÉRÉMENT un domaine faible. Le respect des poids y
 *    serait un défaut.
 *
 * Un drapeau dans une même classe produirait une méthode dont le comportement
 * s'inverse selon un booléen : le jour où l'une des deux règles évolue, l'autre
 * casse en silence.
 *
 * LA DIFFÉRENCE QUI COMPTE — ON NE COMPLÈTE JAMAIS HORS PÉRIMÈTRE.
 *
 * `DiagnosticComposer` complète au niveau de l'épreuve quand un sous-domaine
 * manque de questions : c'est juste pour un diagnostic, qui doit rester
 * représentatif. C'est faux ici. Compléter avec des questions hors sujet
 * transformerait en silence une session ciblée en mini-diagnostic, et le
 * candidat croirait avoir travaillé son point faible.
 *
 * On sert donc moins de questions que demandé, et on dit combien et pourquoi.
 */
final class TrainingComposer
{
    /** En dessous, une session n'apprend rien : mieux vaut refuser que servir. */
    public const MINIMUM_UTILE = 5;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  list<int>  $nodeIds  périmètre STRICT, jamais élargi
     * @return array{
     *     questions: Collection<int, Question>,
     *     disponibles: int,
     *     resservies: int
     * }
     */
    public function compose(Exam $exam, User $user, array $nodeIds, string $locale, int $total): array
    {
        if ($nodeIds === []) {
            return ['questions' => collect(), 'disponibles' => 0, 'resservies' => 0];
        }

        $base = fn () => Question::forDiagnostic()
            ->where('questions.exam_id', $exam->id)
            ->whereIn('questions.competency_node_id', $nodeIds)
            ->where('questions.locale', $locale);

        $questions = $base()
            ->select('questions.*')
            ->selectRaw($this->rangSql(), $this->rangBindings($user))
            ->orderBy('rang')
            ->inRandomOrder()
            ->limit($total)
            ->get();

        return [
            'questions' => $questions->values(),
            'disponibles' => $base()->count(),
            // Rang 2 : déjà réussies, resservies faute de vivier. meta le dit.
            'resservies' => $questions->where('rang', 2)->count(),
        ];
    }

    /** Nombre de questions servables dans ce périmètre, sans en composer une série. */
    public function disponibles(Exam $exam, array $nodeIds, string $locale): int
    {
        if ($nodeIds === []) {
            return 0;
        }

        return Question::forDiagnostic()
            ->where('questions.exam_id', $exam->id)
            ->whereIn('questions.competency_node_id', $nodeIds)
            ->where('questions.locale', $locale)
            ->count();
    }

    /**
     * Rang d'anti-répétition, calculé EN BASE.
     *
     * Charger l'historique du candidat en PHP pour l'y filtrer coûterait une
     * lecture de toutes ses réponses à chaque composition — et cette table est
     * celle qui grossit le plus vite du schéma.
     *
     *   0 — jamais vue : ce qu'on sert d'abord ;
     *   1 — vue et MANQUÉE : le cœur de l'entraînement, la resservir est voulu ;
     *   2 — vue et réussie : dernier recours, quand le vivier est épuisé.
     *
     * Le filtre sur `tenant_id` n'est pas décoratif : `attempts` est isolée par
     * tenant, et une sous-requête écrite à la main ne passe pas par le scope
     * global. Sans lui, l'historique d'un candidat dans un autre organisme
     * influencerait la sélection ici.
     */
    private function rangSql(): string
    {
        return <<<'SQL'
            CASE
                WHEN NOT EXISTS (
                    SELECT 1 FROM attempt_items ai
                    JOIN attempts a ON a.id = ai.attempt_id
                    WHERE ai.question_id = questions.id
                      AND a.user_id = ? AND a.tenant_id = ?
                ) THEN 0
                WHEN EXISTS (
                    SELECT 1 FROM attempt_items ai
                    JOIN attempts a ON a.id = ai.attempt_id
                    JOIN responses r ON r.attempt_item_id = ai.id
                    WHERE ai.question_id = questions.id
                      AND a.user_id = ? AND a.tenant_id = ?
                      AND r.is_correct IS FALSE
                ) THEN 1
                ELSE 2
            END AS rang
        SQL;
    }

    /** @return list<int> */
    private function rangBindings(User $user): array
    {
        $tenantId = $this->tenant->id();

        return [$user->id, $tenantId, $user->id, $tenantId];
    }
}
