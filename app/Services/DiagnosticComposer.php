<?php

namespace App\Services;

use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use Illuminate\Support\Collection;

/**
 * Composition d'une série de diagnostic.
 *
 * Principe : la série reproduit les POIDS OFFICIELS des domaines de l'épreuve,
 * pas une répartition uniforme. Un domaine qui pèse 40 % du concours reçoit
 * 40 % des questions.
 *
 * Sans cela, un candidat fort sur un domaine mineur et faible sur le domaine
 * majeur obtiendrait un score flatteur — et réviserait la mauvaise chose.
 * C'est précisément l'erreur que la plateforme prétend éviter.
 *
 * Aucun score prédictif n'est produit ici, ni ailleurs (METHODE §7.3).
 */
final class DiagnosticComposer
{
    public function __construct(private readonly QuestionIntegrityChecker $checker) {}

    /**
     * @return Collection<int, Question> questions ordonnées, prêtes à présenter
     */
    public function compose(Exam $exam, string $locale, int $total = 10): Collection
    {
        $sousDomaines = CompetencyNode::where('exam_id', $exam->id)
            ->whereNotNull('weight_percent')
            ->where('depth', '>', 0)
            ->get();

        if ($sousDomaines->isEmpty()) {
            return collect();
        }

        $quotas = $this->repartir($sousDomaines, $total);
        $selection = collect();

        foreach ($quotas as $nodeId => $nombre) {
            if ($nombre < 1) {
                continue;
            }

            $selection = $selection->merge(
                Question::forDiagnostic()
                    ->where('exam_id', $exam->id)
                    ->where('competency_node_id', $nodeId)
                    ->where('locale', $locale)
                    ->inRandomOrder()
                    ->limit($nombre)
                    ->get()
            );
        }

        /*
         * Le quota théorique n'est pas toujours servi : un sous-domaine peut
         * manquer de questions publiées. On complète au niveau de l'épreuve
         * plutôt que de rendre une série incomplète — mais on ne dépasse
         * jamais le total demandé.
         */
        if ($selection->count() < $total) {
            $manquant = $total - $selection->count();

            $selection = $selection->merge(
                Question::forDiagnostic()
                    ->where('exam_id', $exam->id)
                    ->where('locale', $locale)
                    ->whereNotIn('id', $selection->pluck('id'))
                    ->inRandomOrder()
                    ->limit($manquant)
                    ->get()
            );
        }

        return $selection->take($total)->values();
    }

    /**
     * Répartition proportionnelle avec la méthode des plus forts restes :
     * une simple troncature perdrait des questions et sur-représenterait les
     * gros domaines.
     *
     * @param  Collection<int, CompetencyNode>  $noeuds
     * @return array<int, int> identifiant de nœud => nombre de questions
     */
    private function repartir(Collection $noeuds, int $total): array
    {
        $sommeDesPoids = (float) $noeuds->sum('weight_percent');

        if ($sommeDesPoids <= 0) {
            return [];
        }

        $exact = [];
        $entier = [];

        foreach ($noeuds as $noeud) {
            $part = ((float) $noeud->weight_percent / $sommeDesPoids) * $total;
            $exact[$noeud->id] = $part;
            $entier[$noeud->id] = (int) floor($part);
        }

        $restant = $total - array_sum($entier);

        // Les restes les plus élevés reçoivent les questions non attribuées.
        $restes = collect($exact)
            ->map(fn ($part, $id) => $part - $entier[$id])
            ->sortDesc();

        foreach ($restes->keys() as $id) {
            if ($restant <= 0) {
                break;
            }
            $entier[$id]++;
            $restant--;
        }

        return $entier;
    }

    /**
     * Une épreuve est-elle diagnosticable ? Utilisé pour n'ouvrir le bouton
     * que lorsqu'une série complète est réellement constructible.
     */
    public function isReady(Exam $exam, string $locale, int $total = 10): bool
    {
        return Question::forDiagnostic()
            ->where('exam_id', $exam->id)
            ->where('locale', $locale)
            ->count() >= $total;
    }
}
