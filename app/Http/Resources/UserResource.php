<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * LISTE BLANCHE stricte des champs exposés.
 *
 * La revue a montré que makeHidden('id') ne suffit pas : il ne protège ni les
 * tableaux manuels, ni le query builder, ni les clés étrangères d'une relation
 * sérialisée. La garantie réelle vient d'ici — on n'énumère que ce qui sort —
 * et du test contractuel récursif qui parcourt chaque réponse JSON.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'status' => $this->status,
            'email_verified' => $this->email_verified_at !== null,
            'phone_verified' => $this->phone_verified_at !== null,
            'roles' => $this->whenLoaded(
                'memberships',
                fn () => $this->memberships->pluck('role.code')->unique()->values()
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
