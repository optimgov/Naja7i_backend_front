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
    public function __construct($resource, private readonly bool $causeVisible)
    {
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
                'rationale' => $option->rationale,
                // La cause n'est rendue que si le quota le permet.
                'cause' => $this->causeVisible ? $option->cause : null,
            ])->values(),
            'cause_locked' => ! $this->causeVisible && $response?->is_correct === false,
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
