<?php

namespace App\Http\Resources;

use App\Support\CapabilityRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un plan, tel que la surface commerciale le montre.
 *
 * LISTE BLANCHE STRICTE, comme partout. `capabilities` conserve le contrat
 * public historique (liste de codes) pour ne pas casser les clients livrés ;
 * `capability_details` fournit la présentation localisée à afficher. Aucun
 * écran candidat ne doit rendre le code brut.
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
            'capability_details' => app(CapabilityRegistry::class)->publicPresentation(
                $this->capabilities,
                app()->getLocale(),
            ),
        ];
    }
}
