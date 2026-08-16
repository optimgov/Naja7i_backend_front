<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'kind' => $this->kind,
            'status' => $this->status,
            'locale' => $this->locale,
            'item_count' => $this->item_count,
            // Le candidat sait ce qu'il a répondu : ce compteur ne dit rien de
            // la justesse.
            'answered_count' => $this->answered_count,

            /*
             * CORRECT_COUNT EST UN ORACLE DE CORRECTION TANT QUE RIEN N'EST FIGÉ.
             *
             * Servi sur une tentative en cours, il se lit une question à la
             * fois : répondre, rappeler la ressource, regarder si le compteur a
             * monté. Le candidat apprend alors la justesse de CHAQUE réponse
             * avant la soumission — c'est la correction, obtenue par une porte
             * qui n'était pas censée l'ouvrir.
             *
             * La garde est ICI, dans la ressource, et pas dans un contrôleur :
             * l'index et la route unitaire la servent tous deux, et la
             * prochaine surface qui la servira l'héritera sans y penser.
             *
             * `submitted_at` et non `status` : c'est la soumission qui FIGE
             * `is_correct`, donc elle seule qui rend ce total licite. Un statut
             * peut changer par un autre chemin — expiration, abandon — sans que
             * la correction ait jamais été calculée.
             *
             * Rendu NUL plutôt qu'absent : le contrat garde la même forme
             * avant et après, et un client n'a pas à distinguer « pas encore »
             * de « champ inconnu ».
             */
            'correct_count' => $this->submitted_at === null ? null : $this->correct_count,

            'started_at' => $this->started_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            /* Quand le candidat a TRAVAILLÉ, non quand il a ouvert. C'est cette
             * date qui permet d'écrire « reprendre — il y a 2 h ». */
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            // Le temps restant vient du serveur : le client ne le calcule jamais.
            'seconds_remaining' => $this->secondsRemaining(),
            'exam' => $this->whenLoaded('exam', fn () => [
                'code' => $this->exam->code,
                'name' => $this->exam->localized('name'),
                'coefficient' => $this->exam->coefficient,

                /*
                 * F3 — LE PREMIER NUMÉRO IMPRIMÉ DU BLOC. Corpus §4.2.4.
                 *
                 * Les blocs ne commencent pas à Q1 : le primaire répond de Q101
                 * à Q125, le collège de Q61 à Q120, sur une feuille de réponses
                 * COMMUNE à plusieurs blocs. Le corpus est net : « Un décalage
                 * de report d'une seule ligne invalide la totalité du bloc. »
                 *
                 * Un examen blanc qui numérote 1, 2, 3 entraîne donc au report
                 * sur la mauvaise ligne — il apprend un geste faux. Le client
                 * numérote à partir d'ici quand la valeur existe.
                 *
                 * NUL = non documenté, et le client repart de 1 comme
                 * aujourd'hui. Ce n'est pas un défaut : c'est l'aveu qu'on ne
                 * connaît pas la numérotation de cette épreuve-là.
                 */
                'first_question_number' => $this->exam->first_question_number,
            ]),
            'items' => AttemptQuestionResource::collection($this->whenLoaded('items')),
        ];
    }
}
