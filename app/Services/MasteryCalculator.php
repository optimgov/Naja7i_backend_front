<?php

namespace App\Services;

use App\Models\AttemptItem;
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
     * Nœuds où des questions ont été servies — répondues ou sautées.
     *
     * Un nœud entre ici dès qu'une tentative close lui a présenté une
     * question, même si le candidat n'en a répondu AUCUNE. C'est le cas que
     * l'ancien code ne voyait pas : partir de `responses` rendait l'évitement
     * indistinguable du jamais-servi, et l'ordonnance le traitait donc en
     * angle mort au lieu d'y voir un refus.
     *
     * ══════════════════════════════════════════════════════════════════════
     * D-09 — UNE LIGNE DE RÉPONSE N'EST PAS UNE RÉPONSE
     *
     * `selected_option_id` peut être NUL : le contrat l'autorise
     * (`'option_uuid' => ['nullable', 'uuid']`), et l'écran de passation en
     * produisait à chaque question traversée sans choix. Ce que devenait une
     * telle ligne, mesuré et non déduit :
     *
     *   - `submit()` lui pose `is_correct = false`, parce que
     *     `selectedOption?->is_correct === true` est faux quand il n'y a pas
     *     d'option ;
     *   - elle entrait donc ici, au dénominateur, avec un poids de 0 ;
     *   - elle grossissait `answered_count`, C'EST-À-DIRE LE VOLUME
     *     D'ÉVIDENCE — la règle non négociable du dépôt veut qu'un score ne
     *     sorte jamais sans lui, et il était faux ;
     *   - déclarée « sûr », elle grossissait `confident_error_count`, le
     *     signal le plus fort de l'ordonnance.
     *
     * Autrement dit, elle produisait exactement ce que `ecrire()` refuse
     * quelques lignes plus bas : « donner au sauteur le score de celui qui
     * s'est trompé ». Le calcul se contredisait lui-même selon que
     * l'évitement passait par une ligne absente ou par une ligne vide.
     *
     * DEUX SERRURES. L'écran de passation n'écrit plus rien sans option
     * choisie ; celle-ci rend l'état inoffensif QUEL QUE SOIT LE CLIENT — et
     * le contrat continue d'accepter `option_uuid: null`, ce qui est
     * légitime : c'est le geste « je passe ».
     *
     * `MemoryScheduler` n'avait pas ce défaut, et c'est une garantie qu'on
     * ignorait avoir : sa requête joint `question_options` par une jointure
     * INTERNE sur `selected_option_id`, donc une réponse sans option n'y
     * entre pas. Aucun rendez-vous de mémoire n'a jamais été créé sur une
     * question non lue.
     * ══════════════════════════════════════════════════════════════════════
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
            ->whereNotNull('responses.selected_option_id')   // et uniquement les vraies réponses
            ->select([
                'attempt_items.competency_node_id',
                'responses.is_correct',
                'responses.confidence',
                'responses.answered_at',
            ])
            ->get()
            ->groupBy('competency_node_id')
            ->keyBy(fn (Collection $groupe, int|string $nodeId) => (int) $nodeId);

        $sautees = $this->sautees($user, $exam);

        $resultats = collect();

        foreach ($reponses->keys()->merge($sautees->keys())->unique() as $nodeId) {
            $resultats->push($this->ecrire(
                $user, $exam, $nodeId,
                $reponses->get($nodeId) ?? collect(),
                $sautees->get($nodeId, 0),
            ));
        }

        return $resultats;
    }

    /**
     * Questions servies et laissées sans réponse, par nœud.
     *
     * La tentative doit être CLOSE : `submitted_at` fait foi, pas le statut —
     * une tentative expirée est soumise elle aussi, et ses items sans réponse
     * ont bien été servis puis laissés. Tant qu'une tentative est en cours,
     * ne pas avoir répondu n'est pas avoir sauté, c'est ne pas avoir fini.
     *
     * DEUX FAÇONS DE NE PAS RÉPONDRE, UN SEUL FAIT. Ne jamais toucher la
     * question laisse l'item sans ligne de réponse ; la traverser en déclarant
     * une certitude laissait une ligne à `selected_option_id` nul. Le candidat
     * n'a rien répondu dans les deux cas, et il y a exactement une chose à
     * compter — voir le D-09 au-dessus de `recomputeLeaves()`.
     *
     * La requête est pilotée par `AttemptItem`, dont le scope global filtre
     * `attempt_items.tenant_id` : les jointures, elles, sont écrites à la main
     * et ne portent aucun scope.
     *
     * @return Collection<int, int>
     */
    private function sautees(User $user, Exam $exam): Collection
    {
        return AttemptItem::query()
            ->join('attempts', 'attempts.id', '=', 'attempt_items.attempt_id')
            ->leftJoin('responses', 'responses.attempt_item_id', '=', 'attempt_items.id')
            ->where('attempts.user_id', $user->id)
            ->where('attempts.exam_id', $exam->id)
            ->whereNotNull('attempts.submitted_at')
            ->where(fn ($q) => $q
                ->whereNull('responses.id')
                ->orWhereNull('responses.selected_option_id'))
            ->groupBy('attempt_items.competency_node_id')
            ->selectRaw('attempt_items.competency_node_id as node_id, count(*) as total')
            ->pluck('total', 'node_id')
            ->mapWithKeys(fn (int|string $total, int|string $nodeId) => [(int) $nodeId => (int) $total]);
    }

    /** @param  Collection<int, object>  $groupe */
    private function ecrire(User $user, Exam $exam, int $nodeId, Collection $groupe, int $sautees): MasteryScore
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

        /*
         * LES SAUTÉES N'ENTRENT PAS AU DÉNOMINATEUR, et ce n'est pas un oubli.
         *
         * Les y mettre donnerait au sauteur exactement le score de celui qui
         * s'est trompé — soit dire qu'éviter une question et la rater sont le
         * même fait. Or l'erreur démontrée est le signal le plus sûr dont
         * dispose le produit, et l'écraser sur une abstention le dégraderait.
         *
         * « De ce qu'il a tenté, tout était juste » est vrai. Ce score n'est
         * pas faux, il est peu informatif — et c'est `evidence` qui le dit,
         * puis l'ordonnance qui en tient compte (RemediationPlanner).
         *
         * `$total` peut valoir zéro quand toutes les questions ont été
         * sautées : l'évidence est alors insuffisante et la division n'est
         * jamais évaluée.
         */
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
                'skipped_count' => $sautees,
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
                $sautees = 0;
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
                    $sautees += $score->skipped_count;

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
                        /* L'évitement remonte l'arbre comme l'évidence : un
                         * domaine dont deux sous-domaines ont été esquivés
                         * doit le dire à son niveau. */
                        'skipped_count' => $sautees,
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
