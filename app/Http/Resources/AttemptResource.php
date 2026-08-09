<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'kind' => $this->kind,
            'status' => $this->status,
            'locale' => $this->locale,
            'item_count' => $this->item_count,
            'answered_count' => $this->answered_count,
            'correct_count' => $this->when($this->status !== 'in_progress', $this->correct_count),
            'started_at' => $this->started_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            // Le temps restant vient du serveur : le client ne le calcule jamais.
            'seconds_remaining' => $this->secondsRemaining(),
            'exam' => $this->whenLoaded('exam', fn () => [
                'code' => $this->exam->code,
                'name' => $this->exam->localized('name'),
                'coefficient' => $this->exam->coefficient,
            ]),
            'items' => AttemptQuestionResource::collection($this->whenLoaded('items')),
        ];
    }
}
