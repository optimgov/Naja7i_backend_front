<?php

namespace App\Http\Resources;

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
 */
class CandidateProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'exam_code' => $this->exam?->code,
            'objective' => $this->objective,
            'target_date' => $this->target_date?->toDateString(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
