<?php

namespace App\Services;

use App\Exceptions\PaiementRefuse;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\QuotaProfile;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Crée, sous verrou, la version correspondant à la projection courante. */
final class PlanVersionService
{
    /** @var list<string> */
    public const CONTRACTUAL_FIELDS = [
        /* Q-19 : le public éligible appartient à la version et versionne. Une
         * demande sur une version dont on n'est plus éligible se refuse ; elle
         * ne se remplace jamais par la version courante (P4). */
        'audience_id',
        'name_fr',
        'name_ar',
        'description_fr',
        'description_ar',
        'price_cents',
        'currency',
        'duration_days',
        /* Le calendrier dit QUAND l'offre se vend — donc s'il est possible
         * d'obtenir quelque chose. La matrice §5 le fait versionner. */
        'sale_opens_at',
        'sale_closes_at',
        'capabilities',
        /* La portée dit CE QUE le droit couvrira : elle versionne. */
        'scope_type',
        'scope_uuid',
        /* La SÉLECTION du profil, pas ses valeurs. Changer de profil est un
         * geste commercial : il versionne. Amender le profil sélectionné n'en
         * est pas un — sinon l'admin pédagogique recomposerait, depuis son
         * registre, des offres qu'elle ne voit pas. */
        'quota_profile_id',
    ];

    /** Ce que la version COPIE du profil, en plus de la sélection elle-même. */
    private const QUOTA_SNAPSHOT = [
        'quota_profile_code',
        'quota_unit',
        'quota_periodicity',
        'quota_value',
        'quota_min_value',
        'quota_max_value',
        'quota_min_justification',
        'quota_max_justification',
    ];

    public function current(Plan $plan): PlanVersion
    {
        return DB::transaction(function () use ($plan): PlanVersion {
            /** @var Plan $locked */
            $locked = Plan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            $latest = $locked->versions()->latest('version')->first();
            $snapshot = $locked->only(self::CONTRACTUAL_FIELDS);

            if ($latest !== null && $this->sameContract($latest, $snapshot)) {
                if ($locked->current_version_id !== $latest->id) {
                    $locked->forceFill(['current_version_id' => $latest->id])->saveQuietly();
                }

                return $latest;
            }

            $version = $locked->versions()->create($snapshot + $this->instantaneDeQuota($locked) + [
                'version' => ($latest?->version ?? 0) + 1,
                'reconstructed' => false,
                /* Qui a signé, et ce qui a bougé (§2.6). `null` se lit
                 * « aucun humain n'a signé » — une composition par semis ou par
                 * migration n'a pas d'auteur, et lui en fabriquer un serait la
                 * première ligne fausse du journal. */
                'composed_by' => auth()->id(),
                'triggered_by' => $latest === null ? [] : $this->champsQuiOntBouge($latest, $snapshot),
            ]);

            $locked->forceFill(['current_version_id' => $version->id])->saveQuietly();

            return $version;
        });
    }

    /**
     * Résout sous verrou la version réellement affichée au candidat. Les
     * anciens clients sans UUID restent acceptés pendant la transition ; un
     * UUID périmé n'est jamais remplacé silencieusement.
     *
     * LE SEUL POINT DE PASSAGE DES DEUX MOYENS DE PAIEMENT, et c'est pourquoi
     * les refus de souscription vivent ici plutôt que dans chaque passerelle :
     * un contrôle recopié dans deux passerelles diverge à la troisième.
     */
    public function purchasable(Plan $plan, ?string $displayedVersionUuid, User $candidat): PlanVersion
    {
        $current = $this->current($plan);

        if ($displayedVersionUuid !== null && $current->uuid !== $displayedVersionUuid) {
            throw new PaiementRefuse('version_indisponible');
        }

        /* HORS CALENDRIER, LA SOUSCRIPTION EST REFUSÉE — et elle l'est ici,
         * pas à l'écran. Le catalogue ne rend déjà plus l'offre ; ce refus
         * couvre le chemin qui ne passe pas par le catalogue (un coupon saisi
         * après la fermeture, un client qui garde un identifiant de version). */
        if (! $current->estCommercialisable()) {
            throw new PaiementRefuse('hors_periode');
        }

        $this->assertEligible($current, $candidat);

        return $current;
    }

    /**
     * LE PUBLIC ÉLIGIBLE EST CONTRACTUEL — Q-19, reporté de M-004 aux murs.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * IL SE LIT SUR LA VERSION, PAS SUR L'OFFRE COURANTE
     *
     * C'est la moitié qui compte. Une offre dont le public change compose une
     * version neuve ; une commande ouverte sur l'ancienne garde le public sous
     * lequel elle a été vendue. Lire `Plan::audience_id` ici referait, à
     * l'envers, le défaut V-3 : appliquer à une demande d'hier la règle
     * d'aujourd'hui.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * ON NE REFUSE QUE CE QU'ON SAIT — PAS CE QU'ON DEVINE
     *
     * La catégorie d'un candidat se DÉDUIT de l'épreuve qu'il a déclarée :
     * épreuve → parcours → famille → catégorie. Un compte sans épreuve déclarée
     * n'a pas de public connu, et lui en supposer un pour lui refuser un achat
     * serait une déduction inventée opposée à quelqu'un qui paie. C'est le même
     * raisonnement que pour le geste ciblé du droit transitoire, et il vaut ici
     * a fortiori : là-bas on s'abstient de DONNER, ici on s'abstiendrait de
     * VENDRE.
     *
     * Une version sans public ne refuse personne : « vide » y signifie « tout
     * le monde », comme au catalogue.
     *
     * LE MESSAGE EST SOBRE. Il dit que l'offre vise une autre catégorie et
     * renvoie au catalogue. Il ne nomme aucun autre compte, et n'apprend rien
     * qui ne soit déjà public.
     */
    private function assertEligible(PlanVersion $version, User $candidat): void
    {
        if ($version->audience_id === null) {
            return;
        }

        /* LECTURE BAS NIVEAU, ET DÉLIBÉRÉE. « Cette personne prépare telle
         * épreuve » est un fait de la PERSONNE, pas l'activité d'un organisme :
         * le lire sous la portée tenant ferait dépendre l'éligibilité d'un
         * achat du centre où le compte est passé en dernier. Même raisonnement
         * que la garde d'attribution de l'ADR-0033 (DET-24). */
        $public = DB::table('candidate_profiles as profil')
            ->join('exams as epreuve', 'epreuve.id', '=', 'profil.exam_id')
            ->join('tracks as parcours', 'parcours.id', '=', 'epreuve.track_id')
            ->join('exam_families as famille', 'famille.id', '=', 'parcours.exam_family_id')
            ->where('profil.user_id', $candidat->getKey())
            ->value('famille.audience_id');

        if ($public === null || (int) $public === (int) $version->audience_id) {
            return;
        }

        throw new PaiementRefuse('public_non_eligible');
    }

    /**
     * LA COQUILLE — le seul UPDATE qu'une version accepte.
     *
     * Corriger « Dècouverte » ne doit pas produire une version 2 : personne
     * n'a rien acheté de neuf, et versionner une faute d'accord ferait du
     * journal des versions un journal de fautes de frappe. La correction
     * amende donc la version EN PLACE — et c'est précisément pour cela qu'elle
     * ne peut pas être discrète : la fonction SQL écrit la ligne de journal et
     * le nouveau texte dans la même transaction, ou ne fait rien.
     *
     * CE SERVICE NE VALIDE RIEN LUI-MÊME, et ce n'est pas un oubli. Le champ
     * autorisé, le motif écrit, le texte non vide et l'identité du texte
     * remplacé sont vérifiés EN BASE, par le canal : une console psql, un
     * correctif à chaud ou un futur écran passent par les mêmes refus. Ce qui
     * appartient à la couche PHP, c'est l'autorisation — la base ne connaît
     * pas les permissions.
     */
    public function corrigerLeTexte(
        PlanVersion $version,
        string $champ,
        string $texte,
        User $acteur,
        string $motif,
    ): PlanVersion {
        Gate::forUser($acteur)->authorize('editorialFix', $version);

        DB::statement('SELECT corriger_version_editoriale(?, ?, ?, ?, ?)', [
            $version->uuid,
            $champ,
            $texte,
            $acteur->getKey(),
            $motif,
        ]);

        return $version->fresh();
    }

    /**
     * LE FIGEMENT. Les valeurs du profil au moment de la composition, copiées.
     *
     * `assertSelectionnable` reste le point de passage : elle vérifie ici, une
     * dernière fois avant que le contrat ne devienne immuable, que le profil
     * est encore proposé et que sa valeur tient dans ses propres bornes. Ce
     * qui est copié ensuite ne se relit plus jamais depuis `quota_profiles` —
     * c'est tout l'objet de P-Q.
     *
     * @return array<string, mixed>
     */
    private function instantaneDeQuota(Plan $plan): array
    {
        if ($plan->quota_profile_id === null) {
            return array_fill_keys(self::QUOTA_SNAPSHOT, null);
        }

        /** @var QuotaProfile $profil */
        $profil = QuotaProfile::query()->whereKey($plan->quota_profile_id)->firstOrFail();
        $capacite = $profil->capability();

        $this->assertVendue($plan, $profil, $capacite);
        app(QuotaProfileService::class)->assertSelectionnable($profil, $capacite);

        return [
            'quota_profile_code' => $profil->code,
            'quota_unit' => $profil->unit,
            'quota_periodicity' => $profil->periodicity,
            'quota_value' => $profil->value,
            'quota_min_value' => $profil->min_value,
            'quota_max_value' => $profil->max_value,
            'quota_min_justification' => $profil->min_justification,
            'quota_max_justification' => $profil->max_justification,
        ];
    }

    /**
     * Une enveloppe sans capacité vendue ne compte rien.
     *
     * Un profil « questions » sur une offre qui ne vend pas `questions.answer`
     * poserait une enveloppe que rien ne débite, et l'écran promettrait
     * quarante questions à qui n'a pas le droit d'en recevoir une seule. Le
     * refus NOMME la capacité manquante : un refus muet se lit comme une panne.
     */
    private function assertVendue(Plan $plan, QuotaProfile $profil, string $capacite): void
    {
        if (in_array($capacite, $plan->capabilities ?? [], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'quota_profile_id' => "Le profil « {$profil->code} » borne {$capacite}, "
                .'que cette offre ne vend pas : une enveloppe sans capacité ne compte rien.',
        ]);
    }

    /**
     * Les champs contractuels qui diffèrent de la version précédente.
     *
     * C'est « le champ qui l'a déclenchée » de la spécification §2.6, au
     * pluriel : un enregistrement peut en changer deux à la fois, et n'en
     * montrer qu'un ferait mentir l'historique.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private function champsQuiOntBouge(PlanVersion $version, array $snapshot): array
    {
        $bouges = [];

        foreach (self::CONTRACTUAL_FIELDS as $field) {
            if ($this->comparable($version->getAttribute($field), $field)
                !== $this->comparable($snapshot[$field] ?? null, $field)) {
                $bouges[] = $field;
            }
        }

        return $bouges;
    }

    /** @param array<string, mixed> $snapshot */
    private function sameContract(PlanVersion $version, array $snapshot): bool
    {
        foreach (self::CONTRACTUAL_FIELDS as $field) {
            $left = $this->comparable($version->getAttribute($field), $field);
            $right = $this->comparable($snapshot[$field] ?? null, $field);

            if ($left !== $right) {
                return false;
            }
        }

        return true;
    }

    /**
     * Deux valeurs identiques doivent se comparer identiques — y compris deux
     * dates.
     *
     * `!==` sur deux `Carbon` compare des IDENTITÉS D'OBJET : deux instants
     * égaux rendraient `false`, et chaque lecture du catalogue composerait une
     * version nouvelle — jusqu'à refuser, pour « version périmée », la commande
     * du candidat qui a l'écran ouvert. Le calendrier de commercialisation est
     * le premier champ contractuel porté par une date : la comparaison se règle
     * ici, une fois.
     */
    private function comparable(mixed $valeur, string $champ): mixed
    {
        if ($champ === 'capabilities') {
            return array_values($valeur ?? []);
        }

        if ($valeur instanceof DateTimeInterface) {
            return $valeur->getTimestamp();
        }

        return $valeur;
    }
}
