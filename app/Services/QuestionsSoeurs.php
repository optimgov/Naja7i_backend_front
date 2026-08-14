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

    /**
     * Le miroir DÉSIGNÉ d'une question, s'il est servable — DET-45, tranché.
     *
     * `questions.mirror_question_id` existe depuis le PAS-5 et n'avait jamais
     * eu d'autorité : F05 choisissait par le couple (compétence, cause), le
     * champ dormait, et personne ne savait plus lequel des deux faisait foi.
     * Le back-office lui donne une surface, et ce sélecteur lui donne la
     * PRIORITÉ : un miroir désigné à la main est plus délibéré qu'un miroir
     * déduit. Le couple reste le repli, et couvre le reste de la banque.
     *
     * SERVABLE N'EST PAS NÉGOCIABLE, et ce n'est pas contredire le rédacteur.
     * La désignation dit « c'est cette question-là » ; elle ne peut pas dire
     * « sers-la même si elle est en brouillon ». Un miroir non publié
     * livrerait à un candidat un contenu qui n'a pas passé la relecture. Quand
     * la désignée n'est pas servable, on se replie sur le couple plutôt que de
     * refuser : le candidat n'a pas à payer une désignation devenue caduque.
     *
     * `mirror_question_id <> id` est garanti en base depuis le PAS-5 : la
     * désignée n'est jamais la question elle-même.
     */
    public function designee(Question $source, string $locale, string $cause): ?Question
    {
        if ($source->mirror_question_id === null) {
            return null;
        }

        return Question::forDiagnostic()
            ->where('questions.id', $source->mirror_question_id)
            ->where('questions.locale', $locale)
            /*
             * MÊME COMPÉTENCE ET MÊME PIÈGE — audit tournée 3, BLOC-2.
             *
             * Cette méthode ne contrôlait que l'identifiant, le statut,
             * l'éligibilité et la langue. Une désignée pouvait donc tendre un
             * AUTRE piège que celui que le candidat venait de rater : l'écran
             * annonçait une vérification de `confusion_notions` et servait une
             * question sans ce distracteur.
             *
             * La conséquence n'était pas seulement trompeuse, elle était
             * mesurable : `MemoryScheduler` fait avancer les couples portés par
             * les distracteurs de la question RÉELLEMENT servie. Une réussite
             * faisait donc progresser d'autres rendez-vous en laissant celui de
             * la cause ratée exactement où il était. F05 cessait d'être une
             * contre-épreuve.
             *
             * La désignation reste PRIORITAIRE (DET-45) — elle n'est simplement
             * plus dispensée de vérifier ce qui fait qu'un miroir est un miroir.
             */
            ->where('questions.competency_node_id', $source->competency_node_id)
            ->whereHas(
                'options',
                fn ($q) => $q->where('is_correct', false)->where('cause', $cause)
            )
            ->with(['options' => fn ($q) => $q->where('is_correct', false)->whereNotNull('cause')])
            ->first();
    }
}
