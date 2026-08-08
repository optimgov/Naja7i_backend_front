<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpecialtyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'name' => $this->localized('name'),
            'cycle' => $this->localized('cycle'),
            'description' => $this->localized('description'),
            'availability' => $this->availability,
            'family' => $this->whenLoaded('family', fn () => [
                'slug' => $this->family->slug,
                'name' => $this->family->localized('name'),
            ]),
        ];
    }
}
