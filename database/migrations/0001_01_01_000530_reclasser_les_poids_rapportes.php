<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DET-60, suite — les nœuds dont la source n'est pas vérifiée passent en
 * `reported`.
 *
 * SÉPARÉE DE LA PRÉCÉDENTE PAR NÉCESSITÉ : PostgreSQL refuse d'employer une
 * valeur d'énumération dans la même transaction que son ajout.
 *
 * LA RÈGLE EST LUE, PAS ÉNUMÉRÉE. On ne liste pas « SE-PSY, SE-PED, SE-SOC » :
 * on déclasse tout nœud marqué `official` dont la source porte `verified_at`
 * nul. Le jour où un descriptif est obtenu et la source vérifiée, la même règle
 * appliquée en sens inverse les rend `official` — et si un nœud sans source du
 * tout se disait officiel, il est déclassé aussi, ce qui est la bonne réponse.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE competency_nodes AS n
            SET provenance = 'reported'
            WHERE n.provenance = 'official'
              AND NOT EXISTS (
                  SELECT 1 FROM sources s
                  WHERE s.id = n.source_id AND s.verified_at IS NOT NULL
              )
        ");
    }

    public function down(): void
    {
        DB::statement("UPDATE competency_nodes SET provenance = 'official' WHERE provenance = 'reported'");
    }
};
