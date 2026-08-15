<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un plan, tel que la surface commerciale le montre.
 *
 * LISTE BLANCHE STRICTE, comme partout. `capabilities` SORT — c'est la seule
 * façon pour l'écran de dire ce que le plan ouvre sans recopier une table de
 * correspondance qui divergerait au premier plan ajouté. Ce sont des codes de
 * capacité, pas des identifiants internes : les exposer ne révèle rien.
 *
 * LE PRIX SORT EN CENTIMES, et la mise en forme appartient à l'écran. Rendre
 * « 199,00 MAD » ici figerait une convention typographique dans l'API, et le
 * RTL en demande une autre.
 */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->localized('name'),
            'description' => $this->localized('description'),
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            /* Nul = sans terme. Le client dit « sans limite de durée », il ne
             * fabrique pas un nombre. */
            'duration_days' => $this->duration_days,
            'capabilities' => $this->capabilities,
        ];
    }
}
