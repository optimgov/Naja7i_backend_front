<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\Question;
use App\Models\ReviewSchedule;
use Illuminate\Support\Collection;

/**
 * Composition d'une session de RÉVISION.
 *
 * Troisième composeur, et troisième principe de sélection — c'est la raison
 * d'une classe de plus plutôt que d'un drapeau :
 *
 *  - `DiagnosticComposer` reproduit les POIDS OFFICIELS de l'épreuve ;
 *  - `TrainingComposer` vise DÉLIBÉRÉMENT un domaine faible ;
 *  - ici, la sélection ne vient d'aucun des deux. Elle vient du CALENDRIER :
 *    ce qui est échu aujourd'hui, dans l'ordre d'urgence des rendez-vous.
 *
 * ON NE RESSERT PAS LA MÊME QUESTION, ON SERT UNE SŒUR.
 *
 * Un rendez-vous porte le couple (compétence, cause). La question à servir est
 * donc n'importe quelle question de cette compétence dont un DISTRACTEUR porte
 * cette cause : elle retend le même piège avec un autre énoncé. Resservir
 * l'item précédent apprendrait l'item — le candidat reconnaîtrait l'énoncé,
 * pas le raisonnement. `last_question_id` sert exactement à cela, et à rien
 * d'autre depuis DET-35 : éviter la répétition, jamais apparier.
 *
 * C'est le point de branchement de F05 (question miroir) : le jour où elle
 * arrivera, seul le choix de la sœur change ici.
 */
final class ReviewComposer
{
    /**
     * Une question par rendez-vous échu, dédoublonnée.
     *
     * UNE QUESTION PEUT COUVRIR PLUSIEURS RENDEZ-VOUS. Ses quatre distracteurs
     * portent jusqu'à quatre causes ; si deux d'entre elles sont échues dans la
     * même compétence, la servir une fois les travaille toutes les deux. La
     * servir deux fois serait absurde — et l'unicité `(attempt_id,
     * question_id)` la refuserait de toute façon.
     *
     * @param  Collection<int, ReviewSchedule>  $rendezVous  échus, déjà ordonnés
     * @return array{
     *     questions: Collection<int, Question>,
     *     couverts: int,
     *     sans_question: int
     * }
     */
    public function compose(Exam $exam, Collection $rendezVous, string $locale, int $total): array
    {
        if ($rendezVous->isEmpty() || $total < 1) {
            return ['questions' => collect(), 'couverts' => 0, 'sans_question' => 0];
        }

        $vivier = $this->vivier($exam, $rendezVous->pluck('competency_node_id')->unique()->all(), $locale);

        $choisies = collect();
        $couverts = 0;
        $sansQuestion = 0;

        foreach ($rendezVous as $rdv) {
            $candidates = $vivier
                ->get($this->cle($rdv->competency_node_id, $rdv->cause), collect());

            if ($candidates->isEmpty()) {
                /* Aucune question ne tend ce piège dans cette compétence : la
                 * banque ne le couvre pas encore. On ne remplace pas par une
                 * question d'à côté — ce serait servir autre chose que ce que
                 * le calendrier a promis. On le compte et on le dit. */
                $sansQuestion++;

                continue;
            }

            // Déjà servie pour un autre rendez-vous : elle couvre celui-ci aussi.
            if ($candidates->contains(fn (Question $q) => $choisies->has($q->id))) {
                $couverts++;

                continue;
            }

            if ($choisies->count() >= $total) {
                continue;   // plafond atteint : le reste est annoncé par l'appelant
            }

            $soeur = $this->soeur($candidates, $rdv);

            $choisies->put($soeur->id, $soeur);
            $couverts++;
        }

        return [
            'questions' => $choisies->values(),
            'couverts' => $couverts,
            'sans_question' => $sansQuestion,
        ];
    }

    /**
     * La sœur : n'importe laquelle, SAUF la dernière servie tant qu'il en reste
     * une autre. S'il n'en reste aucune, on ressert — mieux vaut retravailler
     * le même énoncé que sauter un rendez-vous échu.
     *
     * @param  Collection<int, Question>  $candidates
     */
    private function soeur(Collection $candidates, ReviewSchedule $rdv): Question
    {
        return $candidates->first(fn (Question $q) => $q->id !== $rdv->last_question_id)
            ?? $candidates->first();
    }

    /**
     * Vivier indexé par (compétence, cause).
     *
     * Une seule requête pour toute la session : interroger la banque par
     * rendez-vous aurait coûté vingt allers-retours sur le chemin d'ouverture.
     *
     * @param  list<int>  $nodeIds
     * @return Collection<string, Collection<int, Question>>
     */
    private function vivier(Exam $exam, array $nodeIds, string $locale): Collection
    {
        $questions = Question::forDiagnostic()
            ->where('questions.exam_id', $exam->id)
            ->where('questions.locale', $locale)
            ->whereIn('questions.competency_node_id', $nodeIds)
            ->with(['options' => fn ($q) => $q->where('is_correct', false)->whereNotNull('cause')])
            ->orderBy('questions.id')
            ->get();

        $index = collect();

        foreach ($questions as $question) {
            foreach ($question->options->pluck('cause')->unique() as $cause) {
                $cle = $this->cle($question->competency_node_id, $cause);

                $index->put($cle, ($index->get($cle) ?? collect())->push($question));
            }
        }

        return $index;
    }

    private function cle(int $nodeId, string $cause): string
    {
        return $nodeId.'|'.$cause;
    }
}
