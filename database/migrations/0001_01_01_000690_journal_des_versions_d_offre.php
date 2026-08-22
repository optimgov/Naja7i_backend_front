<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 3A.6, pas 4 — Ce que le journal des versions ne savait pas encore dire.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI MANQUAIT, ET POURQUOI ON NE LE RECRÉE PAS
 *
 * Le lot 3A.4 tient déjà le journal : chaque version porte son numéro, sa date
 * d'effet et le contrat entier tel qu'il était. La spécification §2.6 en demande
 * deux de plus — « l'auteur du changement, et le champ qui l'a déclenchée » — et
 * ceux-là n'étaient nulle part. On les AJOUTE à la ligne existante ; on ne pose
 * pas une table de journal en face de celle qui existe déjà.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * L'AUTEUR EST NULLABLE, ET CE N'EST PAS UNE FACILITÉ
 *
 * Trois versions n'ont pas d'auteur humain, et leur en fabriquer un serait la
 * première ligne fausse du journal : celles que la migration 000610 a
 * reconstruites pour les offres antérieures au versionnement, celles qu'un
 * semis pose, et celles qu'une composition déclenchée hors session
 * d'administration produirait. `NULL` se lit « aucun humain n'a signé », ce qui
 * est exactement le fait.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE CHAMP DÉCLENCHEUR EST UNE LISTE FERMÉE, PAS UN TEXTE
 *
 * Un enregistrement peut changer deux champs à la fois : « le champ » de la
 * spécification est donc une liste. Elle est contenue dans le vocabulaire des
 * champs contractuels — `<@` le vérifie en base — pour qu'une faute de frappe
 * ne devienne pas une catégorie d'audit inventée. Même forme que `capabilities`
 * sur la même table : une liste de codes fermés, pas un JSON libre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_versions', function (Blueprint $table) {
            $table->foreignId('composed_by')->nullable()->after('reconstructed')
                ->constrained('users')->nullOnDelete();
            $table->jsonb('triggered_by')->default(DB::raw("'[]'::jsonb"))->after('composed_by');
        });

        $champs = json_encode([
            'audience_id', 'name_fr', 'name_ar', 'description_fr', 'description_ar',
            'price_cents', 'currency', 'duration_days', 'sale_opens_at', 'sale_closes_at',
            'capabilities', 'scope_type', 'scope_uuid', 'quota_profile_id',
        ]);

        DB::statement(<<<SQL
            ALTER TABLE plan_versions
            ADD CONSTRAINT plan_versions_triggered_by_known
            CHECK (
                jsonb_typeof(triggered_by) = 'array'
                AND triggered_by <@ '{$champs}'::jsonb
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE plan_versions DROP CONSTRAINT IF EXISTS plan_versions_triggered_by_known');

        Schema::table('plan_versions', function (Blueprint $table) {
            $table->dropForeign(['composed_by']);
            $table->dropColumn(['composed_by', 'triggered_by']);
        });
    }
};
