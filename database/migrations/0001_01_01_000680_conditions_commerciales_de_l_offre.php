<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 3A.6, pas 3 — Ce que l'admin commerciale décide, et qui l'engage.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TROIS CHAMPS CONTRACTUELS, UN CHAMP QUI NE L'EST PAS
 *
 * La règle qui résume la colonne « versionne » de la matrice §5 :
 *
 *   « Un champ versionne s'il change ce qu'un candidat obtient ou ce qu'il
 *     paie. Il ne versionne pas s'il change seulement où et quand l'offre se
 *     voit. »
 *
 * Le CALENDRIER de commercialisation est le seul des quatre qui se discute :
 * il dit quand l'offre se voit, ce qui ressemble à « où on le voit ». Mais il
 * décide aussi si la souscription est possible — donc ce qu'on obtient, ou
 * plutôt si l'on obtient. La matrice tranche « oui, il versionne », et la
 * version le porte.
 *
 * La PORTÉE dit ce que le droit couvre : elle versionne, évidemment.
 *
 * La NOTE DE CATALOGUE ne versionne pas et n'existe pas sur la version : c'est
 * un texte interne, non contractuel, que le candidat ne lit jamais. La poser
 * sur `plan_versions` en ferait une promesse par accident.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA PORTÉE RÉUTILISE L'ÉNUMÉRATION DES DROITS, ELLE N'EN CRÉE PAS UNE SECONDE
 *
 * `grant_scope_type` est le type PostgreSQL déjà posé par le lot 3A.2 pour
 * `access_grants`. La composition commerciale s'y branche telle quelle : deux
 * énumérations pour une seule notion divergeraient au premier ajout, et
 * l'interdit du §3 — « créer un type de portée » — ne tiendrait plus que d'un
 * côté. Le couple reste complet ou entièrement nul, comme sur l'octroi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            /* Interne, non contractuelle, jamais rendue au candidat. */
            $table->text('internal_note')->nullable()->after('description_ar');
            $table->timestampTz('sale_opens_at')->nullable()->after('duration_days');
            $table->timestampTz('sale_closes_at')->nullable()->after('sale_opens_at');
            $table->uuid('scope_uuid')->nullable()->after('quota_profile_id');
        });

        Schema::table('plan_versions', function (Blueprint $table) {
            $table->timestampTz('sale_opens_at')->nullable()->after('duration_days');
            $table->timestampTz('sale_closes_at')->nullable()->after('sale_opens_at');
            $table->uuid('scope_uuid')->nullable()->after('quota_max_justification');
        });

        foreach (['plans', 'plan_versions'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN scope_type grant_scope_type NULL");

            /* Un type sans objet ne se résout pas ; un objet sans type ne se
             * lit pas. Même contrainte que sur `access_grants` — c'est la même
             * notion, elle ne se relâche pas parce qu'elle est commerciale. */
            DB::statement(<<<SQL
                ALTER TABLE {$table}
                ADD CONSTRAINT {$table}_scope_pair_complete
                CHECK (num_nonnulls(scope_type, scope_uuid) IN (0, 2))
                SQL);

            /* « Ouverture < fermeture » : une fenêtre fermée avant d'être
             * ouverte n'est pas un calendrier, c'est une offre invisible dont
             * personne ne comprendrait le silence. */
            DB::statement(<<<SQL
                ALTER TABLE {$table}
                ADD CONSTRAINT {$table}_sale_window_ordered
                CHECK (
                    sale_opens_at IS NULL
                    OR sale_closes_at IS NULL
                    OR sale_opens_at < sale_closes_at
                )
                SQL);
        }
    }

    public function down(): void
    {
        foreach (['plans', 'plan_versions'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_sale_window_ordered");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_scope_pair_complete");
            DB::statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS scope_type");
        }

        Schema::table('plan_versions', function (Blueprint $table) {
            $table->dropColumn(['sale_opens_at', 'sale_closes_at', 'scope_uuid']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['internal_note', 'sale_opens_at', 'sale_closes_at', 'scope_uuid']);
        });
    }
};
