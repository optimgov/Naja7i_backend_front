<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionOption;

/**
 * Contrôles éditoriaux d'une question.
 *
 * Ces règles ne sont pas des suggestions : elles décident si une question peut
 * changer de statut. Le back-office les affichera comme une liste de blocages,
 * pas comme des avertissements qu'on peut ignorer.
 *
 * Le principe qui les gouverne, hérité du référentiel CRMEF : mieux vaut une
 * banque plus petite et fiable qu'une banque volumineuse dont le candidat ne
 * peut pas savoir quelles réponses sont sûres.
 */
final class QuestionIntegrityChecker
{
    /**
     * Contrôles applicables à toute question, quel que soit son usage.
     *
     * @return list<string> motifs de blocage, vide si la question est saine
     */
    public function structuralIssues(Question $question): array
    {
        $issues = [];
        $options = $question->options;

        if ($question->kind === 'qcm_single' && $options->count() !== 4) {
            $issues[] = "Un QCM à réponse unique doit compter exactement 4 options, {$options->count()} trouvée(s).";
        }

        $correctes = $options->where('is_correct', true);

        if ($correctes->count() !== 1) {
            $issues[] = "Une question doit avoir exactement une bonne réponse, {$correctes->count()} trouvée(s).";
        }

        foreach ($options as $option) {
            if (trim((string) $option->rationale) === '') {
                $issues[] = "L'option {$option->position} n'a pas de justification.";
            }
        }

        if (trim((string) $question->stem) === '') {
            $issues[] = "L'énoncé est vide.";
        }

        // Une question doit être rattachée au moins au niveau exigé par le
        // profil de taxonomie de son épreuve (ADR-0012).
        $profil = $question->exam?->taxonomyProfile;
        $noeud = $question->node;

        if ($profil !== null && $noeud !== null && ! $profil->allowsPublicationAt($noeud->depth)) {
            $niveauRequis = $profil->levelName($profil->min_depth_for_publication) ?? 'plus fin';
            $issues[] = "Le rattachement doit descendre au moins au niveau « {$niveauRequis} ».";
        }

        if ($noeud !== null && $question->exam_id !== null && $noeud->exam_id !== $question->exam_id) {
            $issues[] = 'Le nœud de compétence appartient à une autre épreuve.';
        }

        return $issues;
    }

    /**
     * Contrôles supplémentaires pour l'éligibilité au diagnostic.
     * Fiche F03 : chaque distracteur doit porter sa cause.
     *
     * @return list<string>
     */
    public function diagnosticIssues(Question $question): array
    {
        $issues = $this->structuralIssues($question);

        $nonEtiquetes = $question->options
            ->where('is_correct', false)
            ->filter(fn (QuestionOption $o) => $o->cause === null);

        foreach ($nonEtiquetes as $option) {
            $issues[] = "Le distracteur {$option->position} n'a pas de cause d'erreur (fiche F03).";
        }

        if ($question->remediation_id === null) {
            $issues[] = 'Une question de diagnostic doit renvoyer vers une remédiation.';
        }

        return $issues;
    }

    /**
     * Contrôles de publication.
     *
     * La source de contenu vérifiée est exigée pour le diagnostic et la
     * simulation, pas pour l'entraînement libre : une question d'entraînement
     * dont la source reste à confirmer peut circuler, à condition d'être
     * signalée. Une question de diagnostic, non — elle oriente la révision.
     *
     * @return list<string>
     */
    public function publicationIssues(Question $question): array
    {
        $issues = $question->eligible_for_diagnostic
            ? $this->diagnosticIssues($question)
            : $this->structuralIssues($question);

        if (! in_array($question->status, ['pedagogically_validated', 'published'], true)) {
            $issues[] = "Une question doit être validée pédagogiquement avant publication, statut actuel : {$question->status}.";
        }

        if ($question->validator_id === null) {
            $issues[] = "Aucun valideur n'est enregistré.";
        }

        // Le valideur n'est jamais le rédacteur : règle permanente §7.2 de
        // docs/METHODE.md. Une relecture par son auteur n'est pas une relecture.
        if ($question->validator_id !== null && $question->validator_id === $question->author_id) {
            $issues[] = "Le valideur ne peut pas être l'auteur de la question.";
        }

        $exigeSource = $question->eligible_for_diagnostic || $question->eligible_for_simulation;

        if ($exigeSource && ! $question->hasVerifiedContentSource()) {
            $issues[] = 'Une question de diagnostic ou de simulation exige au moins une source de contenu vérifiée.';
        }

        return $issues;
    }

    public function canBePublished(Question $question): bool
    {
        return $this->publicationIssues($question) === [];
    }
}
