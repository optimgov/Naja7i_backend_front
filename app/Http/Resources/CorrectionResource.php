<?php

namespace App\Http\Resources;

use App\Models\QuestionOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Correction d'un item, APRÈS soumission uniquement.
 *
 * La cause de l'erreur est soumise au quota de la fiche F03 : deux révélations
 * en compte gratuit. Lorsque le quota est atteint, la justification reste
 * visible mais la CAUSE est remplacée par une invitation — le candidat garde
 * l'explication, il perd le diagnostic.
 *
 * Ce partage est délibéré : masquer aussi la justification transformerait la
 * plateforme en QCM ordinaire pour les comptes gratuits.
 */
class CorrectionResource extends JsonResource
{
    public function __construct(
        $resource,
        private readonly bool $causeVisible,
        private readonly bool $miroirDisponible = false,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $question = $this->question;
        $response = $this->response;
        $choisie = $response?->selectedOption;

        return [
            'item_uuid' => $this->uuid,
            'position' => $this->position,
            'question' => [
                'uuid' => $question->uuid,
                'stem' => $question->stem,
                'explanation' => $question->explanation,
            ],
            'answer' => [
                'selected_option_uuid' => $choisie?->uuid,
                'is_correct' => $response?->is_correct,
                'confidence' => $response?->confidence,
            ],
            'options' => $question->options->map(fn (QuestionOption $option) => [
                'uuid' => $option->uuid,
                'position' => $option->position,
                'content' => $option->content,
                'is_correct' => $option->is_correct,
                /* La justification est GRATUITE par conception : elle est le
                 * contenu éditorial de la question, et la retirer ferait de la
                 * correction un QCM ordinaire pour les comptes gratuits. */
                'rationale' => $option->rationale,

                /*
                 * LA CAUSE DU SEUL DISTRACTEUR CHOISI — audit tournée 3, BLOC-1.
                 *
                 * F03 le dit en toutes lettres : « Lit la cause associée au
                 * DISTRACTEUR CHOISI ». Cette ligne les rendait TOUTES dès que
                 * le quota était ouvert — soit trois hypothèses d'erreur pour
                 * une unité, sur des options que le candidat n'a pas prises.
                 *
                 * Une cause est une hypothèse sur CE QUE LE CANDIDAT A FAIT.
                 * Sur une option qu'il n'a pas choisie, elle ne diagnostique
                 * rien : elle vend le travail éditorial d'étiquetage.
                 *
                 * Si l'abonnement doit un jour ouvrir les causes des autres
                 * distracteurs, ce sera une RÈGLE ÉCRITE — elle n'est pas dans
                 * F03 aujourd'hui.
                 */
                'cause' => $this->causeVisible && $choisie !== null && $option->id === $choisie->id
                    ? $option->cause
                    : null,
            ])->values(),
            'cause_locked' => ! $this->causeVisible && $response?->is_correct === false,

            /*
             * F05 — SEULEMENT L'EXISTENCE D'UN MIROIR, jamais la question.
             *
             * Ni son énoncé, ni ses options, ni même son uuid. Deux raisons, et
             * la seconde est la vraie :
             *
             *  - charger un miroir pour chaque item faux coûterait des requêtes
             *    pour des questions que le candidat n'ouvrira pas ;
             *  - faire voyager un énoncé dans une réponse de CORRECTION
             *    mélangerait deux surfaces que ce dépôt sépare depuis le PAS-6.
             *    `AttemptQuestionResource` et cette classe sont distinctes
             *    précisément pour que rien ne puisse basculer l'une en l'autre.
             *    Cette porte reste fermée.
             *
             * La question du miroir s'obtient en l'OUVRANT, par
             * `POST me/mirrors/{itemUuid}`, et elle arrive alors par la
             * ressource qui sait la servir sans rien révéler.
             */
            'mirror_available' => $this->miroirDisponible,
            'competency' => [
                'code' => $this->node?->code,
                'name' => $this->node?->localized('name'),
            ],
            'remediation' => $question->remediation === null ? null : [
                'uuid' => $question->remediation->uuid,
                'title' => $question->remediation->title,
                'estimated_minutes' => $question->remediation->estimated_minutes,
            ],
        ];
    }
}
