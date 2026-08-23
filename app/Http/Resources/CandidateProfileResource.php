<?php

namespace App\Http\Resources;

use App\Models\Audience;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Le profil candidat — DET-42.
 *
 * LISTE BLANCHE STRICTE, comme partout. Trois champs déclarés et une date de
 * mise à jour ; `id`, `tenant_id`, `user_id` et `exam_id` ne sortent jamais.
 * L'épreuve est désignée par son CODE, qui est l'identifiant public du
 * catalogue et celui que toutes les autres routes acceptent en chemin —
 * `me/mastery/{examCode}`, `me/memory/{examCode}/due`. Le frontend passe de
 * l'un à l'autre sans traduction.
 *
 * LA FORME EST LA MÊME QU'IL Y AIT UN PROFIL OU NON. Un candidat qui n'a rien
 * choisi reçoit ces mêmes clés à `null` — pas une 404, pas un objet vide, pas
 * une forme différente. Le contrôleur y parvient en sérialisant un modèle NON
 * ENREGISTRÉ plutôt qu'en recopiant la liste ici : deux listes blanches pour
 * une seule ressource finiraient par diverger, et le cas « profil absent » est
 * précisément celui qu'on testerait le moins.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * SA CATÉGORIE DE PUBLIC — ET L'EXCEPTION À LA RÈGLE CI-DESSUS
 *
 * `audience` est le seul champ de cette ressource qui DISPARAÎT au lieu de
 * valoir `null`, et l'écart est délibéré. Les trois autres décrivent une
 * DÉCLARATION : « je n'ai pas encore choisi d'épreuve » est une information, et
 * `null` la dit bien. La catégorie, elle, n'est pas déclarée mais DÉDUITE de
 * l'épreuve — épreuve → parcours → famille → catégorie. Sans épreuve, il n'y a
 * rien à déduire, et le champ n'existe pas.
 *
 * *On ne refuse que ce qu'on sait.* C'est déjà la règle du refus de
 * souscription (3A.9 pas 3) : un compte sans épreuve déclarée n'a pas de public
 * connu, et lui en supposer un servirait à refuser une vente à quelqu'un qui
 * paie. Rendre `null` inviterait l'écran à traiter « inconnu » comme une
 * catégorie ; rendre l'absence l'oblige à se poser la question.
 *
 * MÊME FORME QUE SUR LES OFFRES (M-015) : code, `label_fr`, `label_ar`. C'est
 * ce qui rend la comparaison possible en une ligne côté écran — deux formes
 * différentes pour la même notion auraient obligé à traduire l'une dans l'autre,
 * et une traduction se trompe un jour.
 */
class CandidateProfileResource extends JsonResource
{
    /**
     * La catégorie déduite de l'épreuve déclarée, ou `null`.
     *
     * La chaîne est celle que `DroitTransitoireService` et
     * `PlanVersionService::assertEligible()` empruntent déjà : épreuve →
     * parcours → famille → catégorie. Trois lectures de la même déduction
     * existent donc désormais, et c'est une de trop — mais les factoriser
     * demanderait de choisir où elle vit, et la version 1.0 ne le demande pas
     * (DET-97).
     */
    private function categorieDePublic(): ?Audience
    {
        return $this->exam?->track?->family?->audience;
    }

    public function toArray(Request $request): array
    {
        return [
            'exam_code' => $this->exam?->code,
            'objective' => $this->objective,
            'target_date' => $this->target_date?->toDateString(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            /* La catégorie SE DÉDUIT de l'épreuve, elle ne se déclare pas :
             * pas d'épreuve, pas de catégorie, pas de champ. */
            'audience' => $this->when(
                $this->categorieDePublic() !== null,
                fn (): array => [
                    'code' => $this->categorieDePublic()->code,
                    'label_fr' => $this->categorieDePublic()->name_fr,
                    'label_ar' => $this->categorieDePublic()->name_ar,
                ],
            ),
        ];
    }
}
