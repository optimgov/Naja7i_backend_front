<?php

namespace App\Services;

use App\Models\AccessGrantRecord;
use App\Models\Audience;
use App\Models\Plan;
use App\Models\TransitionBatch;
use App\Models\User;
use App\Support\CapabilityRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le droit transitoire des comptes existants — décision Q-17.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE PARAMÉTRAGE D'ABORD, MÊME POUR UN GESTE QUI NE SERVIRA QU'UNE FOIS
 *
 * « 60 jours » est la valeur de DÉPART d'un paramètre, pas une constante : si
 * l'allumage glisse d'un mois, la durée juste n'est plus la même, et personne ne
 * devrait avoir à déployer pour la changer. Durée, public visé, date de pose et
 * offre de référence sont donc les paramètres du geste, bornés et justifiés.
 *
 * Les bornes ne sont pas décoratives. Sous une semaine, un « sevrage annoncé »
 * n'en est pas un — le candidat découvre la fermeture en même temps que la
 * fermeture. Au-delà de six mois, ce n'est plus une transition mais un palier
 * gratuit déguisé, que personne n'a décidé de vendre. La base les tient aussi
 * (`transition_batches_duration_bounded`), parce qu'un service se contourne.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * « ÉQUIVALENT AU PALIER 600 » SE LIT DANS LE CATALOGUE, PAS DANS LE CODE
 *
 * Écrire ici la liste des huit capacités en ferait une seconde source de vérité
 * en face du catalogue : le jour où le palier change, le droit transitoire
 * mentirait. On lit donc la composition d'une OFFRE réelle, et l'on refuse — en
 * nommant la capacité — si elle porte quoi que ce soit de non commercialisable.
 * Filtrer en silence serait pire : le geste réussirait en donnant autre chose
 * que ce qui a été demandé.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA PRÉVISUALISATION N'EST PAS UN CONFORT
 *
 * Q-17 l'exige. Une distribution de droits sur toute une population ne se lance
 * pas sur une intuition du nombre : on annonce combien de comptes recevront,
 * combien ont déjà, et l'on compare après coup. Les trois nombres sont figés
 * dans la trace.
 */
final class DroitTransitoireService
{
    /** La valeur de départ décidée par Q-17 — un paramètre, pas une constante. */
    public const DUREE_DEFAUT = 60;

    /** Sous une semaine, le sevrage n'est pas annoncé : il est subi. */
    public const DUREE_MINIMALE = 7;

    /** Au-delà, ce n'est plus une transition mais un palier gratuit déguisé. */
    public const DUREE_MAXIMALE = 180;

    public const MOTIF_MINIMAL = 10;

    public function __construct(private readonly AbonnementService $abonnements) {}

    /**
     * L'offre dont le droit transitoire copie la composition.
     *
     * Par défaut, la plus complète du catalogue commercial — celle qui compose
     * le plus de capacités vendables, le prix départageant les ex æquo. C'est
     * « le palier 600 » exprimé en fait plutôt qu'en nom : un code en dur
     * cesserait d'être vrai au premier renommage.
     */
    public function offreDeReference(?string $code = null): Plan
    {
        if ($code !== null) {
            $offre = Plan::query()->where('code', $code)->first();

            if ($offre === null) {
                throw ValidationException::withMessages([
                    'offre' => "Aucune offre ne porte le code « {$code} ».",
                ]);
            }

            return $offre;
        }

        $offre = Plan::query()
            ->where('active', true)
            ->where('auto_granted', false)
            ->get()
            ->sortByDesc(fn (Plan $plan): array => [count($plan->capabilities ?? []), $plan->price_cents])
            ->first();

        if ($offre === null) {
            throw ValidationException::withMessages([
                'offre' => 'Aucune offre commerciale au catalogue : il n’y a aucun palier à égaler.',
            ]);
        }

        return $offre;
    }

    /**
     * Les capacités que le droit transitoire ouvrira.
     *
     * @return list<string>
     */
    public function capacitesDe(Plan $offre): array
    {
        $capacites = array_values($offre->capabilities ?? []);

        foreach ($capacites as $capacite) {
            if (! in_array($capacite, CapabilityRegistry::COMMERCIALIZABLE, true)) {
                throw ValidationException::withMessages([
                    'capabilities' => "L’offre « {$offre->code} » compose {$capacite}, "
                        .'qui n’est pas commercialisable : un droit transitoire ne l’ouvrira jamais. '
                        .'Corrigez la composition de l’offre avant de poser le geste.',
                ]);
            }
        }

        if ($capacites === []) {
            throw ValidationException::withMessages([
                'capabilities' => "L’offre « {$offre->code} » ne compose aucune capacité.",
            ]);
        }

        return $capacites;
    }

    /**
     * Ce que la pose ferait, sans rien écrire.
     *
     * @param  array<string, mixed>  $parametres
     * @return array<string, mixed>
     */
    public function previsualiser(array $parametres = []): array
    {
        $offre = $this->offreDeReference($parametres['offre'] ?? null);
        $capacites = $this->capacitesDe($offre);
        $duree = $this->duree($parametres['duree'] ?? self::DUREE_DEFAUT);
        $public = $this->public($parametres['public'] ?? null);
        $depart = $this->depart($parametres['pose_le'] ?? null);

        $nombreVises = $this->comptesVises($public)->count();
        $nombrePorteurs = $this->comptesDejaPorteurs($public);

        return [
            'offre' => $offre->code,
            'version' => $offre->currentVersion()->firstOrFail()->version,
            'capacites' => $capacites,
            'duree_jours' => $duree,
            'public' => $public?->code,
            'pose_le' => $depart->toIso8601String(),
            'fin_prevue' => $depart->copy()->addDays($duree)->toIso8601String(),
            'comptes_vises' => $nombreVises,
            'deja_porteurs' => $nombrePorteurs,
            'a_poser' => $nombreVises - $nombrePorteurs,
        ];
    }

    /**
     * Pose le droit transitoire et rend la trace du geste.
     *
     * @param  array<string, mixed>  $parametres
     */
    public function poser(User $acteur, array $parametres = []): TransitionBatch
    {
        $offre = $this->offreDeReference($parametres['offre'] ?? null);
        $capacites = $this->capacitesDe($offre);
        $duree = $this->duree($parametres['duree'] ?? self::DUREE_DEFAUT);
        $public = $this->public($parametres['public'] ?? null);
        $depart = $this->depart($parametres['pose_le'] ?? null);
        $motif = $this->motif($parametres['motif'] ?? null);
        $version = $offre->currentVersion()->firstOrFail();

        return DB::transaction(function () use (
            $acteur, $offre, $version, $capacites, $duree, $public, $depart, $motif
        ): TransitionBatch {
            $vises = 0;
            $poses = 0;

            $this->comptesVises($public)->orderBy('id')->chunkById(200, function ($comptes) use (
                $capacites, $duree, $depart, $version, &$vises, &$poses
            ): void {
                foreach ($comptes as $compte) {
                    $vises++;

                    if ($this->porteDejaLeTransitoire($compte)) {
                        continue;
                    }

                    foreach ($capacites as $capacite) {
                        AccessGrantRecord::create([
                            'user_id' => $compte->id,
                            'capability' => $capacite,
                            'starts_at' => $depart,
                            'ends_at' => $depart->copy()->addDays($duree),
                            'origin' => 'transition',
                            'origin_reference' => $version->uuid,
                            'note' => "Droit transitoire {$duree} j — palier {$version->plan()->firstOrFail()->code}",
                        ]);
                    }

                    $poses++;
                }
            });

            $trace = new TransitionBatch;
            $trace->forceFill([
                'actor_id' => $acteur->id,
                'plan_id' => $offre->id,
                'plan_version_id' => $version->id,
                'audience_id' => $public?->id,
                'duration_days' => $duree,
                'starts_at' => $depart,
                'reason' => $motif,
                'accounts_targeted' => $vises,
                'accounts_granted' => $poses,
                'accounts_skipped' => $vises - $poses,
                'occurred_at' => now(),
            ])->save();

            return $trace->fresh();
        });
    }

    /** Un compte porte-t-il déjà un droit transitoire encore ouvert ? */
    public function porteDejaLeTransitoire(User $compte): bool
    {
        return AccessGrantRecord::query()
            ->where('user_id', $compte->id)
            ->where('origin', 'transition')
            ->exists();
    }

    /** Les comptes candidats visés par le geste. */
    public function comptesVises(?Audience $public): Builder
    {
        $requete = User::query()->whereHas('memberships.role', fn (Builder $q) => $q
            ->where('code', 'candidat')
        );

        if ($public === null) {
            return $requete;
        }

        /* Le public d'un candidat se DÉDUIT de l'épreuve qu'il a déclarée :
         * épreuve → parcours → famille → catégorie (le rattachement livré au
         * lot 3A.6). Un compte sans épreuve déclarée n'a pas de public connu :
         * il n'est pas visé par un geste ciblé, et lui en supposer un
         * reviendrait à distribuer des droits sur une déduction inventée. */
        return $requete->whereHas('candidateProfile', fn (Builder $profil) => $profil
            ->whereHas('exam.track.family', fn (Builder $famille) => $famille
                ->where('audience_id', $public->id)
            )
        );
    }

    private function comptesDejaPorteurs(?Audience $public): int
    {
        return $this->comptesVises($public)
            ->whereHas('accessGrants', fn (Builder $q) => $q->where('origin', 'transition'))
            ->count();
    }

    private function duree(mixed $valeur): int
    {
        $duree = is_numeric($valeur) ? (int) $valeur : 0;

        if ($duree < self::DUREE_MINIMALE || $duree > self::DUREE_MAXIMALE) {
            throw ValidationException::withMessages([
                'duree' => 'Une transition se compte entre '.self::DUREE_MINIMALE.' et '
                    .self::DUREE_MAXIMALE.' jours : sous une semaine le sevrage est subi, '
                    .'au-delà de six mois ce n’est plus une transition.',
            ]);
        }

        return $duree;
    }

    private function public(mixed $code): ?Audience
    {
        if ($code === null || $code === '') {
            return null;
        }

        $public = Audience::query()->where('code', (string) $code)->first();

        if ($public === null) {
            throw ValidationException::withMessages([
                'public' => "Aucune catégorie de public ne porte le code « {$code} ».",
            ]);
        }

        return $public;
    }

    private function depart(mixed $valeur): Carbon
    {
        if ($valeur === null || $valeur === '') {
            return now();
        }

        $depart = Carbon::parse((string) $valeur);

        if ($depart->isBefore(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'pose_le' => 'Une pose ne se date pas dans le passé : un droit rétroactif '
                    .'serait déjà entamé sans que personne ne l’ait vu.',
            ]);
        }

        return $depart;
    }

    private function motif(mixed $valeur): string
    {
        $motif = is_string($valeur) ? trim($valeur) : '';

        if (mb_strlen($motif) < self::MOTIF_MINIMAL) {
            throw ValidationException::withMessages([
                'motif' => 'Une distribution de droits sans motif écrit ne se relit pas. '
                    .'Dites en une phrase ce que ce geste accompagne.',
            ]);
        }

        return $motif;
    }
}
