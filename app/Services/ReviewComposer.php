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
 * LE SÉLECTEUR EST PARTAGÉ AVEC F05 (question miroir), depuis le PAS-26 :
 * `QuestionsSoeurs` porte le vivier, cette classe garde sa politique de repli.
 * Ce qui diffère entre les deux surfaces n'est pas ce qu'on cherche, mais ce
 * qu'on fait quand on ne trouve rien.
 */
final class ReviewComposer
{
    public function __construct(private readonly QuestionsSoeurs $soeurs) {}

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
     *     sans_question: int,
     *     resservies_identiques: int
     * }
     */
    public function compose(Exam $exam, Collection $rendezVous, string $locale, int $total): array
    {
        if ($rendezVous->isEmpty() || $total < 1) {
            return [
                'questions' => collect(), 'couverts' => 0,
                'sans_question' => 0, 'resservies_identiques' => 0,
            ];
        }

        $vivier = $this->soeurs->vivier(
            $exam, $rendezVous->pluck('competency_node_id')->unique()->all(), $locale
        );

        $choisies = collect();
        $couverts = 0;
        $sansQuestion = 0;
        $identiques = 0;

        foreach ($rendezVous as $rdv) {
            $candidates = $this->soeurs->candidates($vivier, $rdv->competency_node_id, $rdv->cause);

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

            $soeur = $this->soeur($vivier, $candidates, $rdv);

            if ($soeur->id === $rdv->last_question_id) {
                /* Faute de sœur, on ressert l'énoncé déjà vu. Ce n'est ni tu ni
                 * gratuit : l'appelant l'annonce dans `meta`, et
                 * `MemoryScheduler` refuse à cette réussite de faire progresser
                 * la sortie du calendrier. */
                $identiques++;
            }

            $choisies->put($soeur->id, $soeur);
            $couverts++;
        }

        return [
            'questions' => $choisies->values(),
            'couverts' => $couverts,
            'sans_question' => $sansQuestion,
            'resservies_identiques' => $identiques,
        ];
    }

    /**
     * La sœur : n'importe laquelle, SAUF la dernière servie tant qu'il en reste
     * une autre. S'il n'en reste aucune, on ressert — mieux vaut retravailler
     * le même énoncé que sauter un rendez-vous échu, une banque jeune ne
     * comptant souvent qu'une question par couple.
     *
     * CE REPLI NE RAPPORTE PAS LA MÊME CHOSE. Il est compté
     * (`resservies_identiques`), annoncé au client, et `MemoryScheduler` gèle
     * le compteur de sorties sur la réussite qui en découle : reconnaître un
     * énoncé n'est pas maîtriser une cause. Le code d'erreur
     * `MEMORY_NO_SIBLING_QUESTION` reste réservé au cas où le couple n'a
     * AUCUNE question.
     *
     * @param  Collection<string, Collection<int, Question>>  $vivier
     * @param  Collection<int, Question>  $candidates
     */
    private function soeur(Collection $vivier, Collection $candidates, ReviewSchedule $rdv): Question
    {
        return $this->soeurs
            ->autresQue($vivier, $rdv->competency_node_id, $rdv->cause, $rdv->last_question_id)
            ->first()
            ?? $candidates->first();
    }
}
