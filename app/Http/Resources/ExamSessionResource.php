<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `dates_confirmed` et la source sortent TOUJOURS, jamais en option.
 * Le frontend doit pouvoir signaler visuellement une date non confirmée :
 * l'omettre reviendrait à présenter une rumeur comme un fait.
 */
class ExamSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'label' => $this->localizedLabel(),
            'year' => $this->year,
            'registration_opens_on' => $this->registration_opens_on?->toDateString(),
            'registration_closes_on' => $this->registration_closes_on?->toDateString(),
            'written_exam_on' => $this->written_exam_on?->toDateString(),
            'oral_exam_on' => $this->oral_exam_on?->toDateString(),
            'results_on' => $this->results_on?->toDateString(),
            'dates_confirmed' => $this->dates_confirmed,
            'source_url' => $this->source_url,
            'source_note' => app()->getLocale() === 'ar'
                ? ($this->source_note_ar ?: $this->source_note_fr)
                : $this->source_note_fr,
            'family' => $this->whenLoaded('family', fn () => [
                'slug' => $this->family->slug,
                'name' => $this->family->localized('name'),
            ]),
        ];
    }

    private function localizedLabel(): string
    {
        return app()->getLocale() === 'ar' ? ($this->label_ar ?: $this->label_fr) : $this->label_fr;
    }
}
