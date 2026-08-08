<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompetencyNodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'name' => $this->localized('name'),
            'description' => $this->localized('description'),
            'depth' => $this->depth,
            'level_name' => $this->levelName(),

            /*
             * PAS-4.1 — Poids officiel et traçabilité.
             *
             * `weight_percent` reste nul quand le descriptif ne le donne pas :
             * il n'est jamais interpolé. `provenance` accompagne obligatoirement
             * la valeur — un choix éditorial ne doit jamais s'afficher comme une
             * caractéristique officielle du concours. `source` cite le
             * descriptif d'où le poids est tiré, pour que la donnée reste
             * auditable.
             */
            'weight_percent' => $this->weight_percent !== null
                ? (float) $this->weight_percent
                : null,
            'provenance' => $this->provenance,
            'source' => $this->whenLoaded('source', fn () => $this->source?->code),

            'children' => self::collection($this->whenLoaded('children')),
        ];
    }
}
