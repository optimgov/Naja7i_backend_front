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
            /* Trois champs, pas un de plus. Le coefficient est ici la donnée
               qui compte : c'est lui qui montre d'un coup d'œil que la
               spécialité pèse 20 et les sciences de l'éducation 8. Durée,
               langues et format relèvent de la fiche d'épreuve, pas de la
               famille — les exposer ici créerait un second contrat. */
            'exams' => $this->whenLoaded('exams', fn () => $this->exams->map(fn ($exam) => [
                'code' => $exam->code,
                'name' => $exam->localized('name'),
                'coefficient' => $exam->coefficient,
            ])->values()),
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
