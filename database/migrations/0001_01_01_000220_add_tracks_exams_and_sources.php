<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAS-4.1 — Catalogue corrigé d'après les descriptifs officiels CRMEF
 * novembre 2025 (voir docs/regles/CRMEF-2025-referentiel.md).
 *
 * TROIS CORRECTIONS par rapport au PAS-4 :
 *
 * 1. Un niveau PARCOURS s'intercale entre la famille et la spécialité.
 *    Le CRMEF n'est pas un concours unique : primaire bilingue, primaire
 *    amazigh et secondaire ont des épreuves différentes.
 *
 * 2. Les ÉPREUVES deviennent un objet à part entière. Le prototype traitait
 *    « sciences de l'éducation », « didactique » et « spécialité » comme trois
 *    piliers de taxonomie. Ce sont en réalité trois épreuves distinctes, avec
 *    des coefficients (8, 12, 20), des durées (120, 120, 240 min) et des
 *    langues différentes. Les confondre rendrait tout simulateur faux.
 *
 * 3. Chaque donnée porte sa PROVENANCE. Un poids officiel et un choix
 *    éditorial de Naja7i ne doivent jamais être présentés de la même façon.
 *
 * Catalogue toujours GLOBAL : aucune table ne porte tenant_id (ADR-0002).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE data_provenance AS ENUM ('official', 'observed', 'editorial', 'unverified')");
        DB::statement("CREATE TYPE source_kind AS ENUM ('descriptif_officiel', 'texte_reglementaire', 'ouvrage', 'annale', 'autre')");
        DB::statement("CREATE TYPE exam_format AS ENUM ('qcm', 'ecrit', 'oral', 'pratique')");

        // --- Registre des sources ------------------------------------------
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code')->unique();              // SRC-CRMEF-2025-SE
            $table->string('title_fr');
            $table->string('title_ar')->nullable();
            $table->string('authority_fr')->nullable();
            $table->string('authority_ar')->nullable();
            $table->string('session_label')->nullable();   // « Novembre 2025 »
            $table->jsonb('languages')->nullable();
            $table->text('location_note_fr')->nullable();  // « Pages 2-3 : domaines et poids »
            $table->text('location_note_ar')->nullable();
            $table->string('url')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE sources ADD COLUMN kind source_kind NOT NULL DEFAULT 'autre'");

        // --- Parcours : niveau intercalé (correction 1) --------------------
        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('exam_family_id')->constrained()->restrictOnDelete();
            $table->string('slug');
            $table->string('name_fr');
            $table->string('name_ar');
            $table->text('description_fr')->nullable();
            $table->text('description_ar')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['exam_family_id', 'slug']);
        });

        DB::statement("ALTER TABLE tracks ADD COLUMN status publication_status NOT NULL DEFAULT 'draft'");
        DB::statement("ALTER TABLE tracks ADD COLUMN availability catalogue_availability NOT NULL DEFAULT 'waitlist'");

        // --- Spécialité : rattachée au parcours, plus à la famille ----------
        Schema::table('specialties', function (Blueprint $table) {
            $table->foreignId('track_id')->nullable()->after('exam_family_id')
                ->constrained()->restrictOnDelete();

            /*
             * L'unicité suit le rattachement. Le PAS-4 imposait un slug unique
             * par FAMILLE ; avec les parcours, « mathematiques » existe au
             * primaire bilingue ET au secondaire du même concours CRMEF, tout
             * comme « francais » ou « langue-amazighe ». Conserver l'ancienne
             * contrainte rendait le référentiel officiel inchargeable.
             */
            $table->dropUnique('specialties_exam_family_id_slug_unique');
            $table->unique(['track_id', 'slug']);
        });

        // --- Épreuve (correction 2) ----------------------------------------
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('track_id')->constrained()->restrictOnDelete();

            /* NULL = épreuve commune à toutes les spécialités du parcours.
             * C'est le cas de « Sciences de l'éducation » : un seul descriptif
             * pour les treize spécialités du secondaire. */
            $table->foreignId('specialty_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('code')->unique();               // CRMEF-SE-2025
            $table->string('name_fr');
            $table->string('name_ar');

            /* Nullable et NON déduits : le référentiel interdit explicitement
             * d'extrapoler le coefficient d'une spécialité à partir d'une
             * autre. Une valeur inconnue reste nulle, jamais devinée. */
            $table->unsignedSmallInteger('coefficient')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();

            $table->jsonb('languages_allowed')->nullable(); // ["ar","fr"] au choix du candidat
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->index(['track_id', 'specialty_id', 'position']);
        });

        DB::statement("ALTER TABLE exams ADD COLUMN format exam_format NOT NULL DEFAULT 'qcm'");
        DB::statement("ALTER TABLE exams ADD COLUMN status publication_status NOT NULL DEFAULT 'draft'");
        DB::statement("ALTER TABLE exams ADD COLUMN provenance data_provenance NOT NULL DEFAULT 'unverified'");

        // --- Blueprint : la matrice officielle d'une épreuve ----------------
        Schema::create('blueprints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('version');                       // « 2025-11 »

            /* Ces trois champs restent NULS tant qu'une source officielle ne
             * les établit pas. Les descriptifs 2025 donnent les domaines et
             * leurs poids, pas le nombre de questions ni le barème détaillé.
             * Les inventer serait la faute la plus coûteuse de ce projet. */
            $table->unsignedSmallInteger('official_question_count')->nullable();
            $table->text('official_scoring_note_fr')->nullable();
            $table->text('official_admission_threshold_note_fr')->nullable();

            $table->text('coverage_note_fr')->nullable();
            $table->text('coverage_note_ar')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['exam_id', 'version']);
        });

        DB::statement("ALTER TABLE blueprints ADD COLUMN status publication_status NOT NULL DEFAULT 'draft'");

        // Une session appartient à la famille ; on lui ajoute le parcours.
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->foreignId('track_id')->nullable()->after('exam_family_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exam_sessions', fn (Blueprint $t) => $t->dropConstrainedForeignId('track_id'));
        Schema::dropIfExists('blueprints');
        Schema::dropIfExists('exams');
        Schema::table('specialties', fn (Blueprint $t) => $t->dropConstrainedForeignId('track_id'));
        Schema::dropIfExists('tracks');
        Schema::dropIfExists('sources');
        DB::statement('DROP TYPE IF EXISTS exam_format');
        DB::statement('DROP TYPE IF EXISTS source_kind');
        DB::statement('DROP TYPE IF EXISTS data_provenance');
    }
};
