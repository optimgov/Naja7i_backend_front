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
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA CONDITION DE PUBLIC EST DITE, L'OFFRE N'EST PAS CACHÉE — DET-91
 *
 * Depuis le lot 3A.9 pas 3, une souscription sur une offre dont le candidat ne
 * relève pas est refusée côté serveur. Le refus est correct et sobre, mais il
 * arrivait À LA CAISSE : la ressource ne portait aucun champ de public, et
 * l'écran n'avait donc rien à afficher, quoi qu'il veuille bien faire.
 *
 * On DIT la condition ; on ne masque pas l'offre. La route reste publique et
 * complète — un visiteur sans compte lit tout le catalogue, c'est le levier
 * d'acquisition — et la condition affichée ne le referme pas, elle l'explique.
 * Ordonner ou masquer selon le compte connecté reste une décision d'écran.
 *
 * L'ABSENCE DE CONDITION EST L'ABSENCE DU CHAMP : ni chaîne vide, ni « tous ».
 * C'est la règle des murs, appliquée au catalogue — un champ vide se lit comme
 * une condition qu'on n'a pas su nommer.
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
            'version_uuid' => $this->currentVersion?->uuid,
            'capabilities' => $this->capabilities,
            'capability_details' => app(CapabilityRegistry::class)->publicPresentation(
                $this->capabilities,
                app()->getLocale(),
            ),

            /*
             * LE PUBLIC SE LIT SUR L'OFFRE, PAS SUR SA VERSION COURANTE.
             *
             * Ce sont les mêmes valeurs — toute modification contractuelle
             * compose une version neuve — mais l'ordre de causalité compte :
             * `PlanVersionService::purchasable()` appelle `current($plan)`, qui
             * projette `audience_id` DEPUIS L'OFFRE avant de juger. C'est donc
             * cette projection-là qui sera opposée au candidat, et c'est elle
             * qu'on annonce. Lire la version rendrait un instantané que le
             * prochain achat recomposerait.
             *
             * Les DEUX libellés sortent, pas seulement celui de la locale
             * courante. Le code sert à comparer — l'écran rapproche cette
             * condition de la catégorie du candidat — et les libellés servent à
             * l'écrire : un changement de langue ne doit pas obliger à
             * redemander le catalogue pour une phrase de trois mots.
             */
            'audience' => $this->when(
                $this->audience !== null,
                fn (): array => [
                    'code' => $this->audience->code,
                    'label_fr' => $this->audience->name_fr,
                    'label_ar' => $this->audience->name_ar,
                ],
            ),
        ];
    }
}
