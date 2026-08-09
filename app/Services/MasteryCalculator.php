<?php

namespace App\Services;

use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\MasteryScore;
use App\Models\Response;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calcul de la maîtrise.
 *
 * Deux principes non négociables :
 *
 * 1. **La certitude pondère la réussite.** Une réponse juste déclarée « au
 *    hasard » ne vaut pas une réponse juste déclarée « sûr ». Sans cette
 *    pondération, un candidat chanceux serait déclaré compétent — et
 *    n'apprendrait la vérité que le jour du concours.
 *
 * 2. **Pas de score sans évidence** (R04). En dessous du seuil, le score reste
 *    nul et l'interface affiche combien de réponses manquent. Un chiffre
 *    fondé sur deux questions serait cru par le candidat.
 *
 * Ce service ne produit AUCUNE probabilité de réussite au concours, sous aucun
 * nom (METHODE §7.3). Il mesure ce qui a été observé, il ne prédit rien.
 */
final class MasteryCalculator
{
    /**
     * Pondération d'une réponse selon sa certitude déclarée.
     *
     * Le cas décisif est « juste + hasard » à 0,35 : compter 1 masquerait une
     * lacune ; compter 0 punirait un candidat honnête qui a peut-être un
     * savoir partiel. La valeur exacte est un paramètre à ajuster sur données
     * réelles, pas une constante de nature.
     */
    private const POIDS = [
        'correct' => ['sure' => 1.0,  'hesitant' => 0.85, 'guess' => 0.35],
        'wrong' => ['sure' => 0.0,  'hesitant' => 0.0,  'guess' => 0.0],
    ];

    /**
     * Recalcule la maîtrise d'un candidat sur toute une épreuve, puis agrège
     * vers les nœuds parents.
     *
     * @return Collection<int, MasteryScore>
     */
    public function recomputeForExam(User $user, Exam $exam): Collection
    {
        return DB::transaction(function () use ($user, $exam) {
            $feuilles = $this->recomputeLeaves($user, $exam);
            $this->aggregateUpwards($user, $exam);

            return MasteryScore::where('user_id', $user->id)
                ->where('exam_id', $exam->id)
                ->with('node')
                ->get();
        });
    }

    /**
     * Nœuds où des questions ont réellement été répondues.
     *
     * @return Collection<int, MasteryScore>
     */
    private function recomputeLeaves(User $user, Exam $exam): Collection
    {
        $reponses = Response::query()
            ->join('attempt_items', 'attempt_items.id', '=', 'responses.attempt_item_id')
            ->join('attempts', 'attempts.id', '=', 'attempt_items.attempt_id')
            ->where('attempts.user_id', $user->id)
            ->where('attempts.exam_id', $exam->id)
            ->whereNotNull('responses.is_correct')      // uniquement les tentatives soumises
            ->select([
                'attempt_items.competency_node_id',
                'responses.is_correct',
                'responses.confidence',
                'responses.answered_at',
            ])
            ->get()
            ->groupBy('competency_node_id');

        $resultats = collect();

        foreach ($reponses as $nodeId => $groupe) {
            $resultats->push($this->ecrire($user, $exam, (int) $nodeId, $groupe));
        }

        return $resultats;
    }

    /** @param  Collection<int, object>  $groupe */
    private function ecrire(User $user, Exam $exam, int $nodeId, Collection $groupe): MasteryScore
    {
        $total = $groupe->count();
        $justes = $groupe->where('is_correct', true)->count();
        $chance = $groupe->where('is_correct', true)->where('confidence', 'guess')->count();
        $aveugle = $groupe->where('is_correct', false)->where('confidence', 'sure')->count();

        $poidsCumule = 0.0;

        foreach ($groupe as $reponse) {
            $famille = $reponse->is_correct ? 'correct' : 'wrong';
            $poidsCumule += self::POIDS[$famille][$reponse->confidence] ?? 0.0;
        }

        $evidence = MasteryScore::evidenceFor($total);
        $score = $evidence === 'insufficient' ? null : round(($poidsCumule / $total) * 100, 2);

        return MasteryScore::updateOrCreate(
            ['user_id' => $user->id, 'competency_node_id' => $nodeId],
            [
                'exam_id' => $exam->id,
                'score' => $score,
                'evidence' => $evidence,
                'answered_count' => $total,
                'correct_count' => $justes,
                'lucky_guess_count' => $chance,
                'confident_error_count' => $aveugle,
                'last_answered_at' => $groupe->max('answered_at'),
                'computed_at' => now(),
            ]
        );
    }

    /**
     * Agrège vers les parents, du plus profond au plus haut.
     *
     * Le score d'un parent est la moyenne de ses enfants PONDÉRÉE PAR LEUR
     * POIDS OFFICIEL, pas par leur nombre de réponses. Un sous-domaine qui
     * pèse 20 % du concours compte pour 20 %, qu'on y ait répondu à trois ou
     * à trente questions — sinon le candidat qui s'entraîne beaucoup sur un
     * domaine mineur verrait son score global monter sans raison.
     *
     * L'évidence, elle, s'additionne : un parent hérite du volume de ses
     * enfants.
     */
    private function aggregateUpwards(User $user, Exam $exam): void
    {
        $profondeurMax = CompetencyNode::where('exam_id', $exam->id)->max('depth');

        for ($profondeur = $profondeurMax - 1; $profondeur >= 0; $profondeur--) {
            $parents = CompetencyNode::where('exam_id', $exam->id)
                ->where('depth', $profondeur)
                ->get();

            foreach ($parents as $parent) {
                $enfants = CompetencyNode::where('parent_id', $parent->id)->get();

                if ($enfants->isEmpty()) {
                    continue;
                }

                $scores = MasteryScore::where('user_id', $user->id)
                    ->whereIn('competency_node_id', $enfants->pluck('id'))
                    ->get()
                    ->keyBy('competency_node_id');

                if ($scores->isEmpty()) {
                    continue;
                }

                $numerateur = 0.0;
                $poidsVus = 0.0;
                $reponses = 0;
                $chance = 0;
                $aveugle = 0;
                $justes = 0;
                $dernier = null;

                foreach ($enfants as $enfant) {
                    $score = $scores->get($enfant->id);

                    if ($score === null) {
                        continue;
                    }

                    $reponses += $score->answered_count;
                    $justes += $score->correct_count;
                    $chance += $score->lucky_guess_count;
                    $aveugle += $score->confident_error_count;

                    if ($score->last_answered_at !== null
                        && ($dernier === null || $score->last_answered_at->gt($dernier))) {
                        $dernier = $score->last_answered_at;
                    }

                    if ($score->score !== null) {
                        $poids = (float) ($enfant->weight_percent ?? 1);
                        $numerateur += $score->score * $poids;
                        $poidsVus += $poids;
                    }
                }

                $evidence = MasteryScore::evidenceFor($reponses);

                MasteryScore::updateOrCreate(
                    ['user_id' => $user->id, 'competency_node_id' => $parent->id],
                    [
                        'exam_id' => $exam->id,
                        'score' => ($poidsVus > 0 && $evidence !== 'insufficient')
                            ? round($numerateur / $poidsVus, 2)
                            : null,
                        'evidence' => $evidence,
                        'answered_count' => $reponses,
                        'correct_count' => $justes,
                        'lucky_guess_count' => $chance,
                        'confident_error_count' => $aveugle,
                        'last_answered_at' => $dernier,
                        'computed_at' => now(),
                    ]
                );
            }
        }
    }
}
