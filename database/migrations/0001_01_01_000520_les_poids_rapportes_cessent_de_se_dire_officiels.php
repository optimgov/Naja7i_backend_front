<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DET-60 — les poids gardent leur valeur, et cessent de se dire officiels.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI EST FAUX N'EST PAS FORCÉMENT LE CHIFFRE, C'EST NOTRE PRÉTENTION
 *
 * `SE-PSY` 40 / `SE-PED` 30 / `SE-SOC` 30 composent les diagnostics depuis le
 * PAS-4. La chaîne remonte proprement : les nœuds portent `source_id` vers
 * `SRC-CRMEF-2025-SE`, le seeder cite `docs/regles/CRMEF-2025-referentiel-source.md`,
 * et ce document imprime bien ces poids à son §7.2.
 *
 * Mais sa ligne 23 dit : « Tu ne recevras pas les PDF officiels. Les
 * informations utiles qui en ont été extraites sont intégralement consignées
 * dans ce document. **Ne prétends toutefois pas posséder ou avoir consulté les
 * PDF.** » Et le corpus du 15 août établit qu'aucun cadre de référence n'est
 * dans les 33 fichiers.
 *
 * Ces poids sont donc RAPPORTÉS : une origine identifiée, datée, nommée, dont
 * personne dans ce dépôt n'a vu la pièce. Ce ne sont pas des poids inventés, et
 * rien ne les contredit — on les garde. C'est le mot `official` qui doit partir.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * UNE VALEUR AJOUTÉE PLUTÔT QU'UNE VALEUR TORDUE
 *
 * `unverified` existait et signifiait « à valider par un humain ». L'employer
 * ici aurait tenu, mais aurait perdu ce qui compte : la différence entre « ceci
 * a été saisi et demande un contrôle » et « ceci vient d'un descriptif nommé,
 * avec son autorité et sa pagination, que nous n'avons jamais lu ». La seconde
 * situation se répare en OBTENANT UNE PIÈCE, la première en relisant.
 *
 * `reported` porte donc exactement cela, et se lit avec `sources.verified_at` :
 * une provenance rapportée dont la source est vérifiée devient `official` d'un
 * seul geste, le jour où le descriptif arrive.
 *
 * DET-60 RESTE OUVERTE. Cette migration ne la referme pas — elle cesse
 * seulement d'affirmer ce qu'on ne sait pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* PostgreSQL n'ajoute une valeur d'énumération qu'en dehors d'une
         * transaction lorsqu'elle est employée aussitôt. Les migrations de
         * Laravel ne sont pas transactionnelles par défaut : l'ordre suffit. */
        DB::statement("ALTER TYPE data_provenance ADD VALUE IF NOT EXISTS 'reported'");
    }

    public function down(): void
    {
        /*
         * UNE VALEUR D'ÉNUMÉRATION NE SE RETIRE PAS SANS RÉÉCRIRE LE TYPE, et
         * la réécriture casserait toute colonne qui l'emploie. On ne la tente
         * pas pour un retour arrière : `reported` reste déclarée, inutilisée.
         *
         * Le retour des données, lui, est dans la migration suivante.
         */
    }
};
