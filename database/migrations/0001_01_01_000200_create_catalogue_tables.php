<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAS-4 — Catalogue des concours.
 *
 * AUCUNE de ces tables ne porte `tenant_id` — décision structurante de
 * l'ADR-0002, rappelée par l'ADR-0013 : le catalogue est GLOBAL. Deux
 * organismes préparant le CRMEF voient le même concours, la même taxonomie,
 * les mêmes questions. Un concours n'est jamais un tenant.
 *
 * Arborescence (ADR-0013) :
 *   Filière → Famille de concours → Spécialité
 *                                 → Session
 *
 * Les `slug` sont la clé publique des URL, pour le référencement. L'`uuid`
 * reste l'identifiant d'API. L'`id` bigint n'est jamais exposé.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE publication_status AS ENUM ('draft', 'published', 'archived')");
        DB::statement("CREATE TYPE catalogue_availability AS ENUM ('open', 'waitlist', 'closed')");

        // --- Filière : premier niveau. Les « portes » du prototype. ---------
        Schema::create('filieres', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug')->unique();
            $table->string('name_fr');
            $table->string('name_ar');
            $table->text('tagline_fr')->nullable();
            $table->text('tagline_ar')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE filieres ADD COLUMN status publication_status NOT NULL DEFAULT 'draft'");
        DB::statement("ALTER TABLE filieres ADD COLUMN availability catalogue_availability NOT NULL DEFAULT 'waitlist'");

        // --- Famille de concours : CRMEF, ENCG, Médecine… -------------------
        Schema::create('exam_families', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('filiere_id')->constrained()->restrictOnDelete();
            $table->string('slug')->unique();
            $table->string('name_fr');
            $table->string('name_ar');
            $table->string('authority_fr')->nullable();   // organisme organisateur du concours
            $table->string('authority_ar')->nullable();
            $table->text('description_fr')->nullable();
            $table->text('description_ar')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->index(['filiere_id', 'position']);
        });

        DB::statement("ALTER TABLE exam_families ADD COLUMN status publication_status NOT NULL DEFAULT 'draft'");
        DB::statement("ALTER TABLE exam_families ADD COLUMN availability catalogue_availability NOT NULL DEFAULT 'waitlist'");

        // --- Spécialité : Français, Mathématiques… --------------------------
        Schema::create('specialties', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('exam_family_id')->constrained()->restrictOnDelete();
            $table->string('slug');
            $table->string('name_fr');
            $table->string('name_ar');
            $table->string('cycle_fr')->nullable();       // secondaire qualifiant, collégial…
            $table->string('cycle_ar')->nullable();
            $table->text('description_fr')->nullable();
            $table->text('description_ar')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            // Le slug n'est unique que dans sa famille : « francais » peut
            // exister sous CRMEF et sous un autre concours.
            $table->unique(['exam_family_id', 'slug']);
            $table->index(['exam_family_id', 'position']);
        });

        DB::statement("ALTER TABLE specialties ADD COLUMN status publication_status NOT NULL DEFAULT 'draft'");
        DB::statement("ALTER TABLE specialties ADD COLUMN availability catalogue_availability NOT NULL DEFAULT 'waitlist'");

        // --- Session : une édition datée d'un concours ----------------------
        Schema::create('exam_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('exam_family_id')->constrained()->restrictOnDelete();
            $table->string('label_fr');                   // « Session 2026 »
            $table->string('label_ar');
            $table->unsignedSmallInteger('year');
            $table->date('registration_opens_on')->nullable();
            $table->date('registration_closes_on')->nullable();
            $table->date('written_exam_on')->nullable();
            $table->date('oral_exam_on')->nullable();
            $table->date('results_on')->nullable();

            /*
             * Les dates de concours circulent de façon non officielle avant
             * publication. Sans distinction visible entre une date confirmée
             * par le ministère et une date annoncée sur un groupe Facebook,
             * la plateforme reprendrait des rumeurs à son compte.
             */
            $table->boolean('dates_confirmed')->default(false);
            $table->string('source_url')->nullable();
            $table->text('source_note_fr')->nullable();
            $table->text('source_note_ar')->nullable();

            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['exam_family_id', 'year']);
            $table->index(['year', 'written_exam_on']);
        });

        DB::statement("ALTER TABLE exam_sessions ADD COLUMN status publication_status NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_sessions');
        Schema::dropIfExists('specialties');
        Schema::dropIfExists('exam_families');
        Schema::dropIfExists('filieres');
        DB::statement('DROP TYPE IF EXISTS catalogue_availability');
        DB::statement('DROP TYPE IF EXISTS publication_status');
    }
};
