<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PAS-1 · Vague M0 — Extensions PostgreSQL et enums d'identité.
 *
 * Réf. NAJAH-BACK-001 v1.3 §3.1 · Backlog NAJA7i A1-DB (vagues M0–M4).
 * Seuls les enums nécessaires au périmètre du Pas 1 sont créés ici ;
 * les vagues M1–M4 (contenu, activité, commercial, messaging) viendront
 * avec leurs tables respectives.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        DB::statement("CREATE TYPE app_locale AS ENUM ('fr', 'ar')");
        DB::statement("CREATE TYPE user_status AS ENUM ('active', 'suspended', 'deletion_requested', 'anonymized')");
        DB::statement("CREATE TYPE tenant_kind AS ENUM ('platform', 'organization')");
        DB::statement("CREATE TYPE tenant_status AS ENUM ('active', 'suspended', 'closed')");
    }

    public function down(): void
    {
        DB::statement('DROP TYPE IF EXISTS tenant_status');
        DB::statement('DROP TYPE IF EXISTS tenant_kind');
        DB::statement('DROP TYPE IF EXISTS user_status');
        DB::statement('DROP TYPE IF EXISTS app_locale');
        // Les extensions ne sont pas supprimées : elles peuvent servir à d'autres schémas.
    }
};
