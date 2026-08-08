<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAS-4.1 — Carte de couverture du corpus officiel.
 *
 * L'inventaire des sources documentaires recense 32 descriptifs officiels
 * couvrant les trois parcours CRMEF. Trois seulement sont transposés à ce
 * stade.
 *
 * Enregistrer les 29 autres n'est pas de la documentation : c'est ce qui rend
 * le manque MESURABLE. Sans cette carte, la plateforme ne connaît que ce
 * qu'elle contient déjà, et l'on ne peut répondre ni à « quelle proportion du
 * corpus est intégrée ? » ni à « quel concours ouvrir ensuite ? ».
 *
 * Le critère d'ouverture d'un concours devient alors factuel : celui dont le
 * descriptif est réellement transposé, pas celui qui paraît le plus demandé.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE source_component AS ENUM ('sciences_education', 'didactique', 'discipline')");
        DB::statement("CREATE TYPE transposition_status AS ENUM ('transpose', 'identifie_non_transpose', 'introuvable')");

        Schema::table('sources', function (Blueprint $table) {
            $table->foreignId('track_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('specialty_id')->nullable()->constrained()->nullOnDelete();

            /* Libellé de la discipline tel qu'il figure dans l'inventaire.
             * Conservé même lorsqu'aucune spécialité n'existe encore en base :
             * la carte doit rester lisible avant que le catalogue soit complet. */
            $table->string('discipline_label_fr')->nullable();
            $table->text('coverage_note_fr')->nullable();
        });

        DB::statement('ALTER TABLE sources ADD COLUMN component source_component');
        DB::statement(
            "ALTER TABLE sources ADD COLUMN transposition_status transposition_status
             NOT NULL DEFAULT 'identifie_non_transpose'"
        );

        DB::statement('CREATE INDEX sources_coverage_idx ON sources (transposition_status, component)');
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropIndex('sources_coverage_idx');
            $table->dropConstrainedForeignId('specialty_id');
            $table->dropConstrainedForeignId('track_id');
            $table->dropColumn([
                'discipline_label_fr', 'coverage_note_fr', 'component', 'transposition_status',
            ]);
        });

        DB::statement('DROP TYPE IF EXISTS transposition_status');
        DB::statement('DROP TYPE IF EXISTS source_component');
    }
};
