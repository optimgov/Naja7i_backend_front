<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExamFamilyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->localized('name'),
            'authority' => $this->localized('authority'),
            'description' => $this->localized('description'),
            'availability' => $this->availability,
            'filiere' => $this->whenLoaded('filiere', fn () => [
                'slug' => $this->filiere->slug,
                'name' => $this->filiere->localized('name'),
            ]),
            'specialties' => SpecialtyResource::collection($this->whenLoaded('specialties')),
            'sessions' => ExamSessionResource::collection($this->whenLoaded('sessions')),
            'taxonomy' => $this->whenLoaded('taxonomyProfile', fn () => [
                'levels' => collect($this->taxonomyProfile->levels)->map(fn ($l, $i) => [
                    'depth' => $i,
                    'name' => $this->taxonomyProfile->levelName($i),
                ])->values(),
            ]),
        ];
    }
}
