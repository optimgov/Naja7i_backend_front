<?php

namespace App\Services;

use App\Models\CauseRevealCounter;
use App\Models\Response;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Décompte du quota de causes révélées.
 *
 * REVUE PAS-10 BLOC-3 — la version précédente revendiquait atomiquement une
 * RÉPONSE, puis incrémentait le compteur sans condition de plafond. Avec une
 * seule unité restante, deux requêtes portant sur deux réponses différentes
 * réussissaient toutes deux : l'atomicité portait sur le mauvais objet.
 *
 * Ici, l'unité de quota est réservée EN PREMIER par un UPDATE conditionnel sur
 * le compteur. Si la réservation échoue, rien d'autre ne se produit. La
 * ressource rare est le quota, c'est donc lui qu'il faut verrouiller.
 */
final class CauseRevealService
{
    /** @return array{allowed: bool, revealed: int, quota: int} */
    public function status(User $user, bool $hasPremiumAccess): array
    {
        $quota = $this->quota();
        $compteur = CauseRevealCounter::firstOrCreate(['user_id' => $user->id]);

        if ($hasPremiumAccess) {
            return ['allowed' => true, 'revealed' => $compteur->revealed_total, 'quota' => 0];
        }

        return [
            'allowed' => $compteur->revealed_total < $quota,
            'revealed' => $compteur->revealed_total,
            'quota' => $quota,
        ];
    }

    /**
     * Révèle la cause d'une réponse, en consommant une unité si nécessaire.
     *
     * Trois issues :
     *  - déjà révélée : succès, aucune unité consommée ;
     *  - quota disponible : succès, une unité consommée ;
     *  - quota épuisé : échec, rien de modifié.
     */
    public function reveal(User $user, Response $response, bool $hasPremiumAccess): bool
    {
        if ($response->cause_revealed) {
            return true;   // revoir sa correction ne recoûte rien
        }

        /*
         * UNE CAUSE DÉJÀ PAYÉE RESTE OUVERTE, MÊME SUR UN AUTRE ÉNONCÉ.
         *
         * Le PAS-19 avait tenu cette garantie sur la liste de révision et l'y
         * avait bornée, en laissant ouverte la question de son extension à la
         * correction. F05 l'a tranchée en la rendant intenable : la question
         * miroir porte PAR CONSTRUCTION la cause que le candidat vient de voir
         * en correction. Sans cette porte, vérifier qu'on a compris coûterait
         * une seconde unité pour la même explication — le produit ferait payer
         * deux fois ce qu'il présente comme un diagnostic unique.
         *
         * L'unité de quota porte donc sur le COUPLE (compétence, cause) et non
         * sur la réponse. La réponse est marquée révélée sans rien consommer :
         * l'état reste lisible d'un coup d'œil, et les lectures suivantes ne
         * repassent pas par cette jointure.
         */
        if ($this->coupleDejaPaye($user, $response)) {
            Response::where('id', $response->id)
                ->where('cause_revealed', false)
                ->update(['cause_revealed' => true]);

            return true;
        }

        return DB::transaction(function () use ($user, $response, $hasPremiumAccess) {
            CauseRevealCounter::firstOrCreate(['user_id' => $user->id]);

            if ($hasPremiumAccess) {
                $reserve = CauseRevealCounter::where('user_id', $user->id)
                    ->update([
                        'revealed_total' => DB::raw('revealed_total + 1'),
                        'last_revealed_at' => now(),
                        'first_revealed_at' => DB::raw('COALESCE(first_revealed_at, now())'),
                        'updated_at' => now(),
                    ]);
            } else {
                /* Le plafond est dans le WHERE : deux transactions concurrentes
                 * se sérialisent sur la ligne, et la seconde constate que
                 * l'unité n'est plus disponible. */
                $reserve = CauseRevealCounter::where('user_id', $user->id)
                    ->where('revealed_total', '<', $this->quota())
                    ->update([
                        'revealed_total' => DB::raw('revealed_total + 1'),
                        'last_revealed_at' => now(),
                        'first_revealed_at' => DB::raw('COALESCE(first_revealed_at, now())'),
                        'updated_at' => now(),
                    ]);
            }

            if ($reserve !== 1) {
                return false;   // quota épuisé : aucune révélation
            }

            $marquee = Response::where('id', $response->id)
                ->where('cause_revealed', false)
                ->update(['cause_revealed' => true]);

            if ($marquee !== 1) {
                /* Une requête concurrente a marqué la même réponse : on rend
                 * l'unité réservée, sinon une seule cause en coûterait deux. */
                CauseRevealCounter::where('user_id', $user->id)
                    ->update(['revealed_total' => DB::raw('GREATEST(revealed_total - 1, 0)')]);
            }

            return true;
        });
    }

    /**
     * Ce candidat a-t-il déjà payé la cause de CE couple, sur un autre énoncé ?
     *
     * Une réponse sans option choisie, ou dont l'option n'a pas de cause — une
     * bonne réponse — ne relève d'aucun couple : il n'y a rien à retrouver.
     */
    private function coupleDejaPaye(User $user, Response $response): bool
    {
        $item = $response->item;
        $cause = $response->selectedOption?->cause;

        if ($item === null || $cause === null) {
            return false;
        }

        return Response::query()
            ->join('attempt_items', 'attempt_items.id', '=', 'responses.attempt_item_id')
            ->join('attempts', 'attempts.id', '=', 'attempt_items.attempt_id')
            ->join('question_options', 'question_options.id', '=', 'responses.selected_option_id')
            ->where('attempts.user_id', $user->id)
            ->where('responses.cause_revealed', true)
            ->where('attempt_items.competency_node_id', $item->competency_node_id)
            ->where('question_options.cause', $cause)
            ->exists();
    }

    /**
     * Couples (compétence, cause) dont ce candidat a DÉJÀ payé la révélation.
     *
     * `ParcoursController::correction()` engage le produit : « le quota est
     * décompté une seule fois par réponse, revenir sur sa correction ne recoûte
     * rien », et `CauseRevealCounter` n'est jamais remis à zéro pour cette
     * raison. Une cause payée qui réapparaît fermée trois jours plus tard dans
     * la liste de révision rompt cette promesse — le candidat a déjà donné.
     *
     * La révélation est portée par une RÉPONSE ; un rendez-vous porte un
     * COUPLE. Le pont est la jointure ci-dessous : la compétence vient de
     * l'item servi, la cause du distracteur choisi.
     *
     * UNE SEULE REQUÊTE, et un ensemble en retour. Interroger ligne par ligne
     * aurait coûté vingt lectures pour afficher une liste de vingt — c'était
     * l'objection qui avait fait renoncer, elle ne tient pas.
     *
     * @return array<string, true> clés « nodeId|cause »
     */
    public function revealedCouples(User $user): array
    {
        return Response::query()
            ->join('attempt_items', 'attempt_items.id', '=', 'responses.attempt_item_id')
            ->join('attempts', 'attempts.id', '=', 'attempt_items.attempt_id')
            ->join('question_options', 'question_options.id', '=', 'responses.selected_option_id')
            ->where('attempts.user_id', $user->id)
            ->where('responses.cause_revealed', true)
            ->whereNotNull('question_options.cause')
            ->distinct()
            ->selectRaw("attempt_items.competency_node_id || '|' || question_options.cause AS couple")
            ->pluck('couple')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    private function quota(): int
    {
        return (int) config('naja7i.free_cause_quota', 2);
    }
}
