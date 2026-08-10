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

    private function quota(): int
    {
        return (int) config('naja7i.free_cause_quota', 2);
    }
}
