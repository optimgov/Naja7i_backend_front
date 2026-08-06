<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAS-1 — Tenants organisationnels.
 *
 * Règles (NAJAH-BACK-001 v1.3 §1) :
 *  - Le tenant est une ORGANISATION (centre partenaire), jamais un concours,
 *    une spécialité ou une région.
 *  - Un tenant technique unique « platform » (id = 1) héberge tout le B2C
 *    au lancement. Il est inséré ici même, pas dans un seeder optionnel :
 *    aucune installation ne doit exister sans lui.
 *  - Identifiants : bigint interne jamais exposé + UUIDv7 public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug')->unique();
            $table->string('name');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE tenants ADD COLUMN kind tenant_kind NOT NULL DEFAULT 'organization'");
        DB::statement("ALTER TABLE tenants ADD COLUMN status tenant_status NOT NULL DEFAULT 'active'");

        // Le tenant plateforme est structurel, pas une donnée d'exemple.
        DB::statement(
            "INSERT INTO tenants (id, uuid, slug, name, kind, status, created_at, updated_at)
             VALUES (1, gen_random_uuid(), 'platform', 'Naja7i.ma', 'platform', 'active', now(), now())"
        );
        DB::statement("SELECT setval(pg_get_serial_sequence('tenants', 'id'), 1, true)");

        // Un seul tenant plateforme possible, garanti par la base elle-même.
        DB::statement(
            "CREATE UNIQUE INDEX tenants_single_platform ON tenants ((true)) WHERE kind = 'platform'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
