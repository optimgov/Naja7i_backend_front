<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * PAS-1.1 (BLOC-3) — Le garde-fou qui transforme une convention en règle.
 *
 * Le mode de défaillance documenté du projet (écarts X01–X12) est toujours le
 * même : une règle est annoncée dans un document, puis contournée dans le code
 * sans que rien ne l'empêche. Ce test échoue la CI si un chemin de
 * contournement apparaît hors de la liste blanche.
 *
 * DET-18 : cette classe étendait `PHPUnit\Framework\TestCase` tout en appelant
 * `base_path()`, qui exige un conteneur Laravel amorcé. Elle ne passait que par
 * effet de bord de l'ordre d'exécution — lancée seule, parallélisée ou
 * réordonnée, elle serait devenue verte sans rien vérifier. Une garde qui cesse
 * de fonctionner en silence est pire que pas de garde du tout : elle rassure.
 * D'où `Tests\TestCase`, qui amorce l'application.
 */
class TenancyArchitectureTest extends TestCase
{
    /** Seules ces classes ont le droit de manipuler le scope tenant. */
    private const ALLOWED_FILES = [
        'app/Tenancy/TenantAwareBuilder.php',
        'app/Tenancy/TenantBypass.php',
        'app/Tenancy/TenantContext.php',
        'app/Tenancy/InteractsWithTenant.php',
        'app/Models/Concerns/BelongsToTenant.php',
    ];

    /**
     * Tables isolées : tout accès en SQL brut ou query builder est proscrit.
     *
     * PAS-6 ajoute les trois tables d'activité. Contrairement au catalogue, qui
     * est commun à tous les tenants, une tentative appartient à un candidat
     * d'un tenant donné : un accès direct au query builder la sortirait du
     * scope sans que rien ne le signale. PAS-7 y ajoute la maîtrise, qui est
     * l'agrégat le plus sensible : le score d'un candidat.
     */
    private const TENANT_SCOPED_TABLES = [
        'memberships', 'attempts', 'attempt_items', 'responses', 'mastery_scores',
    ];

    private const FORBIDDEN_PATTERNS = [
        'withoutGlobalScope' => 'retire un scope global sans passer par TenantBypass',
        'withoutGlobalScopes' => 'retire tous les scopes globaux',
        'newQueryWithoutScopes' => 'construit une requête sans aucun scope',
        'acrossAllTenants' => 'méthode supprimée au PAS-1.1, remplacée par TenantBypass::run()',
    ];

    public function test_aucun_contournement_du_scope_tenant_hors_liste_blanche(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(base_path('app')) as $file) {
            $relative = str_replace(base_path().'/', '', $file);

            if (in_array($relative, self::ALLOWED_FILES, true)) {
                continue;
            }

            $contents = file_get_contents($file);

            foreach (self::FORBIDDEN_PATTERNS as $pattern => $why) {
                if (str_contains($contents, $pattern)) {
                    $violations[] = "{$relative} : « {$pattern} » {$why}.";
                }
            }
        }

        $this->assertSame([], $violations, implode(PHP_EOL, array_merge(
            ['Contournement(s) du scope tenant détecté(s) :'],
            $violations,
            ['', 'Utilisez TenantBypass::run($raison, $callback), qui journalise le bypass.']
        )));
    }

    public function test_aucun_acces_brut_aux_tables_isolees(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(base_path('app')) as $file) {
            $relative = str_replace(base_path().'/', '', $file);
            $contents = file_get_contents($file);

            foreach (self::TENANT_SCOPED_TABLES as $table) {
                foreach (["DB::table('{$table}'", "DB::table(\"{$table}\""] as $needle) {
                    if (str_contains($contents, $needle)) {
                        $violations[] = "{$relative} : accès query builder direct à « {$table} », "
                            .'qui court-circuite le scope tenant.';
                    }
                }
            }
        }

        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function test_les_modeles_isoles_ne_rendent_pas_tenant_id_assignable(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(base_path('app/Models')) as $file) {
            $contents = file_get_contents($file);

            if (! str_contains($contents, 'use BelongsToTenant')) {
                continue;
            }

            if (preg_match('/\$fillable\s*=\s*\[[^\]]*[\'"]tenant_id[\'"]/s', $contents)) {
                $violations[] = str_replace(base_path().'/', '', $file)
                    .' : tenant_id figure dans $fillable — le trait doit le poser lui-même.';
            }
        }

        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
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
