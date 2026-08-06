<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAS-1 — Utilisateurs.
 *
 * REMPLACE la migration users livrée par défaut avec Laravel
 * (0001_01_01_000000_create_users_table.php), qui doit être supprimée.
 *
 * Règles (NAJAH-BACK-001 v1.3 §1.4 et §3.1) :
 *  - Le compte utilisateur est GLOBAL : pas de tenant_id ici. Le rattachement
 *    à un tenant passe exclusivement par memberships.
 *  - email en citext (unicité insensible à la casse), nullable : l'inscription
 *    par téléphone seul est prévue (OTP, pas ce Pas).
 *  - password nullable : comptes sociaux sans mot de passe (table identities,
 *    Pas ultérieur). Règle §8.3 : jamais de compte sans méthode de connexion —
 *    contrainte applicative au moment où identities existera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('phone')->nullable()->unique();      // E.164
            $table->string('password')->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE users ADD COLUMN email citext');
        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE email IS NOT NULL');
        DB::statement("ALTER TABLE users ADD COLUMN locale app_locale NOT NULL DEFAULT 'fr'");
        DB::statement("ALTER TABLE users ADD COLUMN status user_status NOT NULL DEFAULT 'active'");

        // Un utilisateur doit être joignable : e-mail ou téléphone.
        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_email_or_phone CHECK (email IS NOT NULL OR phone IS NOT NULL)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
