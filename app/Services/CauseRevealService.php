<?php

namespace App\Services;

use App\Models\CauseAcquisition;
use App\Models\CauseRevealCounter;
use App\Models\Response;
use App\Models\User;
use App\Support\UniqueViolation;
use Illuminate\Database\QueryException;
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

        $couple = $this->couple($response);

        if ($couple === null) {
            return true;   // bonne réponse, ou aucune option choisie : rien à ouvrir
        }

        return $this->acquerir($user, $couple[0], $couple[1], $hasPremiumAccess, $response);
    }

    /**
     * Acquiert un couple (compétence, cause), ou constate qu'il l'est déjà.
     *
     * AUDIT TOURNÉE 2, BLOC-2 — C'EST L'INSERTION QUI ARBITRE, PAS UNE LECTURE.
     *
     * L'acquisition était auparavant DÉDUITE par une jointure, hors
     * transaction, avant la réservation. Deux révélations concurrentes du même
     * couple portées par deux réponses différentes lisaient toutes deux « pas
     * encore acquis » et consommaient chacune une unité. Le plafond restait
     * atomique — il l'est depuis le PAS-10 — mais la nouvelle unité, elle, ne
     * l'était pas.
     *
     * L'ordre compte : on INSÈRE d'abord dans `cause_acquisitions`, dont
     * l'index unique tranche entre deux transactions concurrentes ; on ne
     * réserve l'unité qu'ensuite, et si la réservation échoue le point de
     * reprise annule aussi l'acquisition. Aucune des deux écritures ne survit
     * sans l'autre.
     *
     * Rien n'est consommé quand la ligne existe déjà : c'est la garantie du
     * PAS-19 — une cause payée reste ouverte — devenue structurelle.
     */
    public function acquerir(
        User $user,
        int $nodeId,
        string $cause,
        bool $hasPremiumAccess,
        ?Response $response = null,
    ): bool {
        return DB::transaction(function () use ($user, $nodeId, $cause, $hasPremiumAccess, $response) {
            $nouvelle = $this->insererAcquisition($user, $nodeId, $cause, $hasPremiumAccess, $response);

            if (! $nouvelle) {
                // Déjà acquis : gratuit, par construction.
                $this->marquerRevelee($response);

                return true;
            }

            CauseRevealCounter::firstOrCreate(['user_id' => $user->id]);

            $compteur = CauseRevealCounter::where('user_id', $user->id)
                ->when(
                    ! $hasPremiumAccess,
                    /* Le plafond est dans le WHERE : deux transactions se
                     * sérialisent sur la ligne, et la seconde constate que
                     * l'unité n'est plus disponible. */
                    fn ($q) => $q->where('revealed_total', '<', $this->quota())
                );

            $reserve = $compteur->update([
                'revealed_total' => DB::raw('revealed_total + 1'),
                'last_revealed_at' => now(),
                'first_revealed_at' => DB::raw('COALESCE(first_revealed_at, now())'),
                'updated_at' => now(),
            ]);

            if ($reserve !== 1) {
                /* Quota épuisé : l'acquisition insérée juste avant ne doit pas
                 * survivre. Sans ce retrait, le couple serait acquis sans avoir
                 * été payé — et toutes les révélations suivantes gratuites. */
                CauseAcquisition::where('user_id', $user->id)
                    ->where('competency_node_id', $nodeId)
                    ->where('cause', $cause)
                    ->delete();

                return false;
            }

            $this->marquerRevelee($response);

            return true;
        });
    }

    /**
     * Le couple (compétence, cause) que cette réponse engage, s'il y en a un.
     *
     * Une bonne réponse ne porte aucune cause (contrainte du PAS-5), et une
     * réponse sans option choisie non plus : ni l'une ni l'autre n'ouvre quoi
     * que ce soit.
     *
     * @return array{0: int, 1: string}|null
     */
    private function couple(Response $response): ?array
    {
        $item = $response->item;
        $cause = $response->selectedOption?->cause;

        return ($item === null || $cause === null)
            ? null
            : [$item->competency_node_id, $cause];
    }

    /**
     * Insère l'acquisition, ou rend `false` si le couple est déjà acquis.
     *
     * LA VIOLATION D'INDEX EST UNE RÉPONSE, PAS UNE ERREUR — même mécanique
     * qu'au PAS-21 BLOC-2. Le point de reprise n'est pas décoratif : en
     * PostgreSQL une erreur avorte la transaction entière, et sans `SAVEPOINT`
     * le rattrapage ne servirait à rien.
     */
    private function insererAcquisition(
        User $user,
        int $nodeId,
        string $cause,
        bool $hasPremiumAccess,
        ?Response $response,
    ): bool {
        try {
            DB::transaction(fn () => CauseAcquisition::create([
                'user_id' => $user->id,
                'competency_node_id' => $nodeId,
                'cause' => $cause,
                'response_id' => $response?->id,
                'granted_by_access' => $hasPremiumAccess,
                'acquired_at' => now(),
            ]));

            return true;
        } catch (QueryException $e) {
            if (! UniqueViolation::on($e, 'cause_acquisitions_unique')) {
                throw $e;
            }

            return false;
        }
    }

    private function marquerRevelee(?Response $response): void
    {
        if ($response === null) {
            return;
        }

        Response::where('id', $response->id)
            ->where('cause_revealed', false)
            ->update(['cause_revealed' => true]);
    }

    /** Ce candidat a-t-il acquis CE couple ? Une lecture, plus une déduction. */
    public function possede(User $user, int $nodeId, string $cause): bool
    {
        return CauseAcquisition::where('user_id', $user->id)
            ->where('competency_node_id', $nodeId)
            ->where('cause', $cause)
            ->exists();
    }

    /**
     * Couples (compétence, cause) déjà acquis par ce candidat.
     *
     * Une seule requête, et une lecture DIRECTE depuis le PAS-28 : l'acquis
     * n'est plus déduit d'une jointure sur les réponses révélées, il est là.
     *
     * @return array<string, true> clés « nodeId|cause »
     */
    public function revealedCouples(User $user): array
    {
        return CauseAcquisition::where('user_id', $user->id)
            ->get(['competency_node_id', 'cause'])
            ->mapWithKeys(fn (CauseAcquisition $a) => [
                $a->competency_node_id.'|'.$a->cause => true,
            ])
            ->all();
    }

    private function quota(): int
    {
        return (int) config('naja7i.free_cause_quota', 2);
    }
}
