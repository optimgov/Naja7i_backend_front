<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Question TELLE QU'ELLE EST PRÉSENTÉE pendant une tentative.
 *
 * Cette ressource est la plus sensible du projet. Elle n'expose NI la bonne
 * réponse, NI les justifications, NI les causes d'erreur. Un candidat qui
 * ouvrirait l'onglet réseau de son navigateur ne doit rien pouvoir déduire.
 *
 * C'est pourquoi elle est une LISTE BLANCHE stricte et non un modèle masqué :
 * un champ ajouté demain au modèle n'apparaîtra pas ici par accident.
 *
 * La ressource de correction (CorrectionResource) est une classe SÉPARÉE,
 * volontairement — pour qu'aucune option d'affichage ne puisse basculer l'une
 * en l'autre.
 */
class AttemptQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $question = $this->question;

        return [
            'item_uuid' => $this->uuid,
            'position' => $this->position,
            'question' => [
                'uuid' => $question->uuid,
                'stem' => $question->stem,
                'kind' => $question->kind,
                'options' => $question->options->map(fn ($option) => [
                    'uuid' => $option->uuid,
                    'position' => $option->position,
                    'content' => $option->content,
                    // is_correct, rationale et cause : ABSENTS, par conception.
                ])->values(),
            ],
            'answered' => $this->response !== null,
            'confidence' => $this->response?->confidence,
            'selected_option_uuid' => $this->response?->selectedOption?->uuid,
        ];
    }
}
