<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FiliereResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->localized('name'),
            'tagline' => $this->localized('tagline'),
            'availability' => $this->availability,
            'families' => ExamFamilyResource::collection($this->whenLoaded('families')),
        ];
    }
}
