<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\Question;
use Illuminate\Support\Collection;

/**
 * Les questions qui tendent LE MÊME PIÈGE, indexées par (compétence, cause).
 *
 * Deux surfaces en ont besoin et n'en auront jamais deux définitions :
 * `ReviewComposer`, qui sert un rendez-vous échu, et la question MIROIR (F05),
 * qui vérifie qu'une explication a pris. Extrait ici plutôt que recopié —
 * trois sélecteurs du même concept divergent, c'est la leçon de DET-30 et du
 * `scopeForDiagnostic` dont le nom a fini par mentir.
 *
 * CE QUI EST PARTAGÉ, ET CE QUI NE L'EST PAS. Le vivier l'est : « quelles
 * questions de cette compétence portent cette cause sur un distracteur » a une
 * seule bonne réponse. La POLITIQUE DE REPLI ne l'est pas, et c'est délibéré :
 *
 *  - la révision ressert l'énoncé déjà vu faute de mieux — sauter une échéance
 *    serait pire, et le prix est payé ailleurs (palier plafonné, sortie
 *    fermée) ;
 *  - le miroir REFUSE — sa raison d'être est de changer d'énoncé. Le resservir
 *    ne vérifierait rien du tout.
 *
 * Les deux appellent donc `autresQue()` et décident eux-mêmes de ce qu'ils font
 * d'un résultat vide. Cacher cette divergence dans une option booléenne
 * produirait une méthode dont le sens s'inverse selon un drapeau.
 */
final class QuestionsSoeurs
{
    /**
     * Vivier indexé par (compétence, cause), en UNE requête.
     *
     * Interroger la banque couple par couple coûterait un aller-retour par
     * rendez-vous — vingt sur une séance de révision plafonnée.
     *
     * @param  list<int>  $nodeIds
     * @return Collection<string, Collection<int, Question>>
     */
    public function vivier(Exam $exam, array $nodeIds, string $locale): Collection
    {
        if ($nodeIds === []) {
            return collect();
        }

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

    /**
     * Les sœurs d'un couple, la question déjà servie exclue.
     *
     * Rendre une collection plutôt qu'une question : l'appelant décide s'il se
     * rabat sur l'exclue quand il ne reste rien, et cette décision-là leur est
     * propre.
     *
     * @param  Collection<string, Collection<int, Question>>  $vivier
     * @return Collection<int, Question>
     */
    public function autresQue(Collection $vivier, int $nodeId, string $cause, ?int $exclue): Collection
    {
        return $this->candidates($vivier, $nodeId, $cause)
            ->reject(fn (Question $q) => $q->id === $exclue)
            ->values();
    }

    /**
     * Toutes les questions du couple, sans exclusion.
     *
     * @param  Collection<string, Collection<int, Question>>  $vivier
     * @return Collection<int, Question>
     */
    public function candidates(Collection $vivier, int $nodeId, string $cause): Collection
    {
        return $vivier->get($this->cle($nodeId, $cause), collect());
    }

    public function cle(int $nodeId, string $cause): string
    {
        return $nodeId.'|'.$cause;
    }
}
