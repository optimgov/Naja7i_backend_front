<?php

namespace App\Services;

use App\Enums\QuotaPeriodicity;
use App\Enums\QuotaProfileEventType;
use App\Enums\QuotaUnit;
use App\Models\QuotaProfile;
use App\Models\QuotaProfileEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Le seul chemin d'écriture d'un profil de quota.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TROIS GARANTIES, ET AUCUNE N'EST DÉCORATIVE
 *
 * 1. LES BORNES SE FRANCHISSENT AVEC UNE RAISON ÉCRITE. Abaisser une borne en
 *    conservant la justification de l'ancienne, c'est l'abaisser SANS
 *    justification — le scénario S-16 le refuse explicitement (« l'admin
 *    pédagogique abaisse la borne basse à 5 sans justification écrite →
 *    refusé »). Le service exige donc une justification RENOUVELÉE, et pas
 *    seulement non vide : sinon le refus se contourne en ne touchant à rien.
 *
 * 2. L'UNITÉ ET LE CODE SONT FIGÉS APRÈS CRÉATION. Une version d'offre
 *    référencera ce profil ; changer son unité changerait la capacité qu'il
 *    borne, sous une offre déjà vendue. C'est le défaut V-3 transposé.
 *
 * 3. CHAQUE GESTE LAISSE SON AUTEUR, SON AVANT ET SON APRÈS. Une borne dont
 *    on ne peut pas relire l'histoire n'est pas une borne justifiée, c'est un
 *    nombre.
 *
 * La base tient les mêmes invariants par CHECK. Ce n'est pas une redondance
 * inutile : le service NOMME la borne à qui la franchit, la contrainte
 * garantit qu'aucun autre chemin ne s'en dispense.
 */
final class QuotaProfileService
{
    /** Longueur minimale d'une justification opposable — la même qu'en base. */
    public const JUSTIFICATION_MINIMALE = 20;

    /** @param array<string, mixed> $attributs */
    public function definir(User $acteur, array $attributs): QuotaProfile
    {
        $unite = $this->unite($attributs['unit'] ?? null);
        $periodicite = $this->periodicite($attributs['periodicity'] ?? QuotaPeriodicity::CUMULATIVE_GRANT);

        $valeurs = [
            'code' => $this->code($attributs['code'] ?? null),
            'name_fr' => $this->texte($attributs['name_fr'] ?? null, 'name_fr', 'Le nom français est obligatoire.'),
            'name_ar' => $this->texte($attributs['name_ar'] ?? null, 'name_ar', 'Le nom arabe est obligatoire.'),
            'unit' => $unite,
            'periodicity' => $periodicite,
            'value' => $this->entier($attributs['value'] ?? null, 'value'),
            'min_value' => $this->entier($attributs['min_value'] ?? null, 'min_value'),
            'max_value' => $this->entier($attributs['max_value'] ?? null, 'max_value'),
            'min_justification' => $this->justification($attributs['min_justification'] ?? null, 'min_justification'),
            'max_justification' => $this->justification($attributs['max_justification'] ?? null, 'max_justification'),
            'active' => (bool) ($attributs['active'] ?? true),
            'position' => (int) ($attributs['position'] ?? 0),
        ];

        $this->assertCoherence($valeurs['min_value'], $valeurs['value'], $valeurs['max_value']);

        return DB::transaction(function () use ($acteur, $valeurs): QuotaProfile {
            $profil = new QuotaProfile;
            $profil->forceFill($valeurs)->save();

            $this->journaliser($profil, $acteur, QuotaProfileEventType::DEFINED, [], $this->instantane($profil));

            return $profil->fresh();
        });
    }

    /**
     * Amender un profil existant. Seul ce qui est fourni est examiné.
     *
     * @param  array<string, mixed>  $attributs
     */
    public function amender(QuotaProfile $profil, User $acteur, array $attributs): QuotaProfile
    {
        $this->assertFige($profil, $attributs);

        $avant = $this->instantane($profil);
        $modifications = [];

        foreach (['name_fr', 'name_ar'] as $champ) {
            if (array_key_exists($champ, $attributs)) {
                $modifications[$champ] = $this->texte(
                    $attributs[$champ], $champ, 'Un profil se nomme dans les deux langues.'
                );
            }
        }

        if (array_key_exists('value', $attributs)) {
            $modifications['value'] = $this->entier($attributs['value'], 'value');
        }

        foreach ([
            'min_value' => 'min_justification',
            'max_value' => 'max_justification',
        ] as $borne => $champJustification) {
            if (! array_key_exists($borne, $attributs)) {
                continue;
            }

            $nouvelle = $this->entier($attributs[$borne], $borne);

            if ($nouvelle === $profil->getAttribute($borne)) {
                continue;
            }

            $modifications[$borne] = $nouvelle;
            $modifications[$champJustification] = $this->justificationRenouvelee(
                $attributs[$champJustification] ?? null,
                $profil->getAttribute($champJustification),
                $champJustification,
                $borne,
            );
        }

        /* Réécrire une justification sans toucher à sa borne reste permis :
         * préciser une raison n'est pas la contourner. */
        foreach (['min_justification', 'max_justification'] as $champ) {
            if (array_key_exists($champ, $attributs) && ! array_key_exists($champ, $modifications)) {
                $modifications[$champ] = $this->justification($attributs[$champ], $champ);
            }
        }

        foreach (['active' => 'boolean', 'position' => 'integer'] as $champ => $type) {
            if (array_key_exists($champ, $attributs)) {
                $modifications[$champ] = $type === 'boolean'
                    ? (bool) $attributs[$champ]
                    : (int) $attributs[$champ];
            }
        }

        $this->assertCoherence(
            $modifications['min_value'] ?? $profil->min_value,
            $modifications['value'] ?? $profil->value,
            $modifications['max_value'] ?? $profil->max_value,
        );

        return DB::transaction(function () use ($profil, $acteur, $modifications, $avant): QuotaProfile {
            $profil->forceFill($modifications)->save();
            $profil->refresh();

            $apres = $this->instantane($profil);

            foreach ($this->gestes($avant, $apres) as $type) {
                $this->journaliser($profil, $acteur, $type, $avant, $apres);
            }

            return $profil;
        });
    }

    /**
     * LA GARDE QUE LE PAS COMMERCIAL APPELLERA — et qu'aucun écran ne remplace.
     *
     * Une requête forgée peut porter n'importe quel profil et n'importe quel
     * nombre. Ce qui la refuse n'est pas l'absence de champ de saisie à
     * l'écran, c'est cette vérification : le profil existe, il est encore
     * proposé, son unité est celle que la capacité consomme, et la valeur
     * retenue est exactement la sienne — dans ses bornes. Le message NOMME la
     * borne franchie, parce qu'un refus muet se lit comme une panne.
     */
    public function assertSelectionnable(
        QuotaProfile $profil,
        string $capacite,
        ?int $valeurDemandee = null,
    ): QuotaProfile {
        if (! $profil->active) {
            throw ValidationException::withMessages([
                'quota_profile' => "Le profil de quota « {$profil->code} » n’est plus proposé à la sélection.",
            ]);
        }

        if ($profil->capability() !== $capacite) {
            throw ValidationException::withMessages([
                'quota_profile' => "Le profil « {$profil->code} » compte des {$profil->unit->label()} : "
                    ."il borne {$profil->capability()}, jamais {$capacite}.",
            ]);
        }

        $valeur = $valeurDemandee ?? $profil->value;

        if ($valeur !== $profil->value) {
            throw ValidationException::withMessages([
                'quota_profile' => "Un quota ne se saisit pas : le profil « {$profil->code} » vaut "
                    ."{$profil->value} {$profil->unit->label()}, et {$valeur} n'a franchi aucune borne pédagogique.",
            ]);
        }

        if (! $profil->contient($valeur)) {
            throw ValidationException::withMessages([
                'quota_profile' => "La valeur {$valeur} sort des bornes du profil « {$profil->code} » "
                    ."(borne basse {$profil->min_value}, borne haute {$profil->max_value}).",
            ]);
        }

        return $profil;
    }

    /**
     * Ce que l'admin commerciale pourra sélectionner pour une capacité.
     *
     * @return array<string, string>
     */
    public function selectionnablesPour(string $capacite, ?string $locale = null): array
    {
        $unites = array_values(array_filter(
            QuotaUnit::cases(),
            fn (QuotaUnit $unite): bool => $unite->capability() === $capacite,
        ));

        if ($unites === []) {
            return [];
        }

        return QuotaProfile::query()
            ->active()
            ->whereIn('unit', array_map(fn (QuotaUnit $unite): string => $unite->value, $unites))
            ->ordered()
            ->get()
            ->mapWithKeys(fn (QuotaProfile $profil): array => [
                $profil->code => $profil->localized('name', $locale).' — '
                    .$profil->value.' '.$profil->unit->label(),
            ])
            ->all();
    }

    /** @param array<string, mixed> $attributs */
    private function assertFige(QuotaProfile $profil, array $attributs): void
    {
        if (array_key_exists('code', $attributs) && $attributs['code'] !== $profil->code) {
            throw ValidationException::withMessages([
                'code' => 'Le code d’un profil de quota est figé : une version d’offre le désigne.',
            ]);
        }

        if (array_key_exists('unit', $attributs)
            && $this->unite($attributs['unit']) !== $profil->unit) {
            throw ValidationException::withMessages([
                'unit' => 'L’unité d’un profil est figée : la changer changerait la capacité qu’il borne, '
                    .'sous une offre déjà vendue.',
            ]);
        }

        if (array_key_exists('periodicity', $attributs)
            && $this->periodicite($attributs['periodicity']) !== $profil->periodicity) {
            throw ValidationException::withMessages([
                'periodicity' => 'La fenêtre d’un quota est une règle de consommation, pas un réglage.',
            ]);
        }
    }

    private function assertCoherence(int $borneBasse, int $valeur, int $borneHaute): void
    {
        if ($borneBasse <= 0) {
            throw ValidationException::withMessages([
                'min_value' => 'Une borne basse nulle n’est pas une borne : elle autorise tout.',
            ]);
        }

        if ($borneBasse > $borneHaute) {
            throw ValidationException::withMessages([
                'min_value' => "La borne basse {$borneBasse} dépasse la borne haute {$borneHaute}.",
            ]);
        }

        if ($valeur < $borneBasse) {
            throw ValidationException::withMessages([
                'value' => "La valeur {$valeur} passe sous la borne basse {$borneBasse}.",
            ]);
        }

        if ($valeur > $borneHaute) {
            throw ValidationException::withMessages([
                'value' => "La valeur {$valeur} dépasse la borne haute {$borneHaute}.",
            ]);
        }
    }

    private function unite(mixed $valeur): QuotaUnit
    {
        if ($valeur instanceof QuotaUnit) {
            return $valeur;
        }

        $unite = is_string($valeur) ? QuotaUnit::tryFrom($valeur) : null;

        if ($unite === null) {
            $montre = is_scalar($valeur) ? (string) $valeur : get_debug_type($valeur);

            throw ValidationException::withMessages([
                'unit' => "L’unité « {$montre} » n’est comptée par aucune capacité du produit.",
            ]);
        }

        return $unite;
    }

    private function periodicite(mixed $valeur): QuotaPeriodicity
    {
        if ($valeur instanceof QuotaPeriodicity) {
            return $valeur;
        }

        $periodicite = is_string($valeur) ? QuotaPeriodicity::tryFrom($valeur) : null;

        if ($periodicite === null) {
            $montre = is_scalar($valeur) ? (string) $valeur : get_debug_type($valeur);

            throw ValidationException::withMessages([
                'periodicity' => "La fenêtre « {$montre} » n’est pas une règle de consommation du produit.",
            ]);
        }

        return $periodicite;
    }

    private function code(mixed $valeur): string
    {
        $code = is_string($valeur) ? trim($valeur) : '';

        if (preg_match('/^[a-z][a-z0-9-]{2,63}$/', $code) !== 1) {
            throw ValidationException::withMessages([
                'code' => 'Un code de profil est en minuscules, sans espace, de trois caractères au moins.',
            ]);
        }

        return $code;
    }

    private function texte(mixed $valeur, string $champ, string $message): string
    {
        $texte = is_string($valeur) ? trim($valeur) : '';

        if ($texte === '') {
            throw ValidationException::withMessages([$champ => $message]);
        }

        return $texte;
    }

    /**
     * Un quota se compte en unités entières — quelle que soit la forme reçue.
     *
     * Le formulaire déclare `integer()`, mais un champ numérique HTML rend une
     * chaîne et un appel de service peut rendre un flottant. On accepte donc
     * les trois formes ENTIÈRES et on refuse tout le reste : « 39,5 questions »
     * n'est pas une valeur qu'on arrondit en silence.
     */
    private function entier(mixed $valeur, string $champ): int
    {
        if (is_int($valeur)) {
            return $valeur;
        }

        if (is_float($valeur) && floor($valeur) === $valeur) {
            return (int) $valeur;
        }

        if (is_string($valeur) && preg_match('/^\d+$/', trim($valeur)) === 1) {
            return (int) trim($valeur);
        }

        throw ValidationException::withMessages([
            $champ => 'Un quota se compte en unités entières.',
        ]);
    }

    private function justification(mixed $valeur, string $champ): string
    {
        $texte = is_string($valeur) ? trim($valeur) : '';

        if (mb_strlen($texte) < self::JUSTIFICATION_MINIMALE) {
            throw ValidationException::withMessages([
                $champ => 'Une borne sans raison écrite n’est pas une borne. '
                    .'Écrivez ce que cette limite protège, en une phrase au moins.',
            ]);
        }

        return $texte;
    }

    private function justificationRenouvelee(
        mixed $valeur,
        ?string $actuelle,
        string $champ,
        string $borne,
    ): string {
        $texte = $this->justification($valeur, $champ);

        if ($actuelle !== null && trim($actuelle) === $texte) {
            throw ValidationException::withMessages([
                $champ => "Déplacer « {$borne} » en conservant la justification de l’ancienne borne, "
                    .'c’est la déplacer sans justification. Écrivez ce que la nouvelle limite protège.',
            ]);
        }

        return $texte;
    }

    /**
     * @param  array<string, mixed>  $avant
     * @param  array<string, mixed>  $apres
     * @return list<QuotaProfileEventType>
     */
    private function gestes(array $avant, array $apres): array
    {
        $gestes = [];

        $aChange = fn (array $champs): bool => (bool) array_filter(
            $champs,
            fn (string $champ): bool => ($avant[$champ] ?? null) !== ($apres[$champ] ?? null),
        );

        if ($aChange(['name_fr', 'name_ar'])) {
            $gestes[] = QuotaProfileEventType::RENAMED;
        }

        if ($aChange(['value'])) {
            $gestes[] = QuotaProfileEventType::VALUE_CHANGED;
        }

        if ($aChange(['min_value', 'max_value', 'min_justification', 'max_justification'])) {
            $gestes[] = QuotaProfileEventType::BOUNDS_CHANGED;
        }

        if ($aChange(['active'])) {
            $gestes[] = QuotaProfileEventType::AVAILABILITY_CHANGED;
        }

        return $gestes;
    }

    /** @return array<string, mixed> */
    private function instantane(QuotaProfile $profil): array
    {
        return [
            'code' => $profil->code,
            'name_fr' => $profil->name_fr,
            'name_ar' => $profil->name_ar,
            'unit' => $profil->unit->value,
            'periodicity' => $profil->periodicity->value,
            'value' => $profil->value,
            'min_value' => $profil->min_value,
            'max_value' => $profil->max_value,
            'min_justification' => $profil->min_justification,
            'max_justification' => $profil->max_justification,
            'active' => $profil->active,
        ];
    }

    /**
     * @param  array<string, mixed>  $avant
     * @param  array<string, mixed>  $apres
     */
    private function journaliser(
        QuotaProfile $profil,
        User $acteur,
        QuotaProfileEventType $type,
        array $avant,
        array $apres,
    ): void {
        $evenement = new QuotaProfileEvent;
        $evenement->forceFill([
            'quota_profile_id' => $profil->id,
            'actor_id' => $acteur->id,
            'event_type' => $type,
            /* La base exige un OBJET JSON des deux côtés : un tableau PHP vide
             * s'encoderait en `[]`, et l'« avant » d'une définition est
             * précisément vide. On garde la contrainte, on lui donne `{}`. */
            'before' => $avant === [] ? new stdClass : $avant,
            'after' => $apres === [] ? new stdClass : $apres,
            'occurred_at' => now(),
        ])->save();
    }
}
