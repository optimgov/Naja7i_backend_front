<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une commande, vue par le CANDIDAT.
 *
 * CE QUI N'EN SORT JAMAIS : `refusal_reason`. Le motif d'un refus est interne
 * — « virement non reçu », « coupon revendu » — et regarde l'équipe. Le
 * candidat apprend que sa demande n'a pas abouti et il a une adresse pour en
 * parler ; lui servir le motif brut transformerait une décision commerciale en
 * accusation.
 *
 * C'est la même règle que DET-50 côté éditorial, et elle est tenue par le
 * `$hidden` du modèle ET par cette liste blanche : deux serrures, parce qu'un
 * champ ajouté demain ne doit pas apparaître par accident.
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'method' => $this->method,
            /* Le montant FIGÉ, pas le prix courant du plan. */
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'created_at' => $this->created_at?->toIso8601String(),
            'honored_at' => $this->honored_at?->toIso8601String(),
            'plan' => $this->whenLoaded('plan', fn () => [
                'code' => $this->plan->code,
                'name' => $this->plan->localized('name'),
            ]),
        ];
    }
}
