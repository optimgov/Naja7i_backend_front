<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Garde architecturale — version renforcée.
 *
 * REVUE PAS-1.1 BLOC-1 : la version précédente ne cherchait que les chaînes
 * littérales `DB::table('memberships')`. Elle laissait passer
 * `DB::table($variable)`, `DB::connection()->table(...)`, `DB::select(...)` et
 * l'accès au query builder par le conteneur.
 *
 * L'acceptation « le test architectural échoue en cas de contournement » était
 * donc fausse dans sa formulation absolue.
 *
 * Cette version interdit **toute** primitive d'accès bas niveau dans `app/`,
 * hors liste blanche d'infrastructure — plutôt que d'énumérer les noms de
 * tables, qui sera toujours une course perdue. Un service métier n'a aucune
 * raison d'écrire du SQL brut ; s'il en a une, elle est nommée et justifiée
 * par une entrée dans `ALLOWED_LOW_LEVEL`.
 *
 * **Limite assumée, à ne pas masquer :** ce contrôle est syntaxique. Il ne
 * détecte ni le SQL construit par concaténation à l'exécution, ni un appel
 * traversant une abstraction tierce. La défense de profondeur reste la
 * Row-Level Security PostgreSQL, différée au gate B2B (ADR-0002).
 */
class TenancyArchitectureTest extends TestCase
{
    /** Classes autorisées à manipuler le scope tenant. */
    private const ALLOWED_SCOPE = [
        'app/Tenancy/TenantAwareBuilder.php',
        'app/Tenancy/TenantBypass.php',
        'app/Tenancy/TenantContext.php',
        'app/Tenancy/InteractsWithTenant.php',
        'app/Models/Concerns/BelongsToTenant.php',
    ];

    /**
     * Classes autorisées à employer des primitives d'accès bas niveau.
     * Toute addition à cette liste doit être justifiée en revue.
     */
    private const ALLOWED_LOW_LEVEL = [
        'app/Services/AttemptService.php',        // incrément conditionnel, DB::raw
        'app/Services/MasteryCalculator.php',     // jointure d'agrégation, lecture seule
        'app/Services/PermissionResolver.php',    // jointure de référentiel, hors tenant
    ];

    private const FORBIDDEN_SCOPE_PATTERNS = [
        'withoutGlobalScope' => 'retire un scope global sans passer par TenantBypass',
        'withoutGlobalScopes' => 'retire tous les scopes globaux',
        'newQueryWithoutScopes' => 'construit une requête sans aucun scope',
        'acrossAllTenants' => 'méthode supprimée au PAS-1.1, remplacée par TenantBypass::run()',
    ];

    /**
     * Primitives d'accès bas niveau, quelle que soit la table visée.
     * On ne cherche plus des noms de tables : on cherche le CHEMIN.
     */
    private const FORBIDDEN_LOW_LEVEL = [
        'DB::table(' => 'query builder direct, court-circuite le scope tenant',
        'DB::connection(' => 'connexion directe, court-circuite le scope tenant',
        'DB::select(' => 'SQL brut en lecture',
        'DB::insert(' => 'SQL brut en écriture',
        'DB::update(' => 'SQL brut en écriture',
        'DB::delete(' => 'SQL brut en écriture',
        'DB::statement(' => 'SQL brut arbitraire',
        'DB::unprepared(' => 'SQL brut non préparé',
        '::getConnection()->table(' => 'query builder via la connexion du modèle',
        'app(\'db\')' => 'résolution du gestionnaire de base par le conteneur',
        'app("db")' => 'résolution du gestionnaire de base par le conteneur',
    ];

    public function test_aucun_contournement_du_scope_tenant(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(base_path('app')) as $file) {
            $relatif = $this->relative($file);

            if (in_array($relatif, self::ALLOWED_SCOPE, true)) {
                continue;
            }

            $contenu = file_get_contents($file);

            foreach (self::FORBIDDEN_SCOPE_PATTERNS as $motif => $raison) {
                if (str_contains($contenu, $motif)) {
                    $violations[] = "{$relatif} : « {$motif} » {$raison}.";
                }
            }
        }

        $this->assertSame([], $violations, implode(PHP_EOL, array_merge(
            ['Contournement(s) du scope tenant :'], $violations,
            ['', 'Utilisez TenantBypass::run($raison, $callback), qui journalise.']
        )));
    }

    /**
     * Interdit les primitives d'accès bas niveau, dynamiques comprises.
     * C'est la correction du BLOC-1 : `DB::table($variable)` est désormais
     * détecté, puisque c'est l'appel qui est cherché, pas son argument.
     */
    public function test_aucune_primitive_d_acces_bas_niveau_hors_liste_blanche(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(base_path('app')) as $file) {
            $relatif = $this->relative($file);

            if (in_array($relatif, self::ALLOWED_LOW_LEVEL, true)) {
                continue;
            }

            $contenu = $this->sansCommentaires(file_get_contents($file));

            foreach (self::FORBIDDEN_LOW_LEVEL as $motif => $raison) {
                if (str_contains($contenu, $motif)) {
                    $violations[] = "{$relatif} : « {$motif} » — {$raison}.";
                }
            }
        }

        $this->assertSame([], $violations, implode(PHP_EOL, array_merge(
            ['Accès bas niveau détecté(s) dans app/ :'], $violations,
            ['', 'Passez par Eloquent. Si l\'accès est légitime, ajoutez le fichier à ALLOWED_LOW_LEVEL avec sa justification.']
        )));
    }

    public function test_les_modeles_isoles_ne_rendent_pas_tenant_id_assignable(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(base_path('app/Models')) as $file) {
            $contenu = file_get_contents($file);

            if (! str_contains($contenu, 'use BelongsToTenant')) {
                continue;
            }

            if (preg_match('/\$fillable\s*=\s*\[[^\]]*[\'"]tenant_id[\'"]/s', $contenu)) {
                $violations[] = $this->relative($file).' : tenant_id figure dans $fillable.';
            }
        }

        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    /**
     * REVUE PAS-5 BLOC-1 : les champs de transition éditoriale ne doivent
     * jamais être assignables en masse — c'est ce qui permettait de créer une
     * question directement publiée, sans contrôle.
     */
    public function test_les_champs_de_transition_editoriale_ne_sont_pas_assignables(): void
    {
        $contenu = file_get_contents(base_path('app/Models/Question.php'));

        preg_match('/\$fillable\s*=\s*\[(.*?)\]/s', $contenu, $matches);
        $fillable = $matches[1] ?? '';

        foreach ([
            'status', 'published_at', 'retired_at', 'validator_id',
            'reviewer_id', 'version', 'supersedes_id',
            'eligible_for_diagnostic', 'eligible_for_simulation',
        ] as $champ) {
            $this->assertStringNotContainsString(
                "'{$champ}'", $fillable,
                "« {$champ} » ne doit pas être assignable en masse : les transitions passent par QuestionTransitionService."
            );
        }
    }

    /** Retire commentaires et docblocs, pour ne pas signaler un exemple cité. */
    private function sansCommentaires(string $code): string
    {
        $sortie = '';

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $sortie .= is_array($token) ? $token[1] : $token;
        }

        return $sortie;
    }

    private function relative(string $file): string
    {
        return str_replace(base_path().'/', '', $file);
    }

    /** @return list<string> */
    private function phpFilesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
