<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAS-6 — Tentatives et réponses.
 *
 * Première table d'ACTIVITÉ du projet : contrairement au catalogue, elle porte
 * `tenant_id` et le trait BelongsToTenant (matrice §1.4, ADR-0002).
 *
 * Quatre exigences gouvernent ce schéma :
 *
 * 1. AUCUNE RÉPONSE PERDUE. Un candidat en examen blanc dont le réseau coupe
 *    ne doit pas perdre son travail. Chaque réponse est enregistrée seule, au
 *    fil de l'eau, jamais à la soumission finale.
 *
 * 2. IDEMPOTENCE. Un double clic, un renvoi automatique ou un retour arrière
 *    ne doivent créer ni doublon ni écrasement silencieux.
 *
 * 3. HORODATAGE SERVEUR. L'heure du client n'est jamais autoritative : elle
 *    est conservée pour diagnostic, mais le chronomètre repose sur le serveur.
 *    Sans cela, avancer l'horloge de son téléphone donnerait du temps en plus.
 *
 * 4. LA QUESTION EST FIGÉE. Une tentative pointe vers la VERSION de question
 *    réellement présentée. Corriger une question demain ne doit pas changer
 *    la correction affichée hier.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE attempt_kind AS ENUM ('diagnostic', 'training', 'simulation', 'mirror')");
        DB::statement("CREATE TYPE attempt_status AS ENUM ('in_progress', 'submitted', 'expired', 'abandoned')");

        /* Certitude+ (F02). Déclarée AVANT de voir la correction, elle
         * distingue « je sais » de « j'ai deviné juste » — deux situations que
         * le score seul confond, et qui appellent des remédiations opposées. */
        DB::statement("CREATE TYPE answer_confidence AS ENUM ('sure', 'hesitant', 'guess')");

        Schema::create('attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('specialty_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('locale', 2);

            /* Clé fournie par le client. Rejouer la même création retourne la
             * tentative existante au lieu d'en ouvrir une seconde. */
            $table->string('idempotency_key', 64);

            $table->timestampTz('started_at');
            $table->timestampTz('expires_at')->nullable();   // null = sans limite (entraînement)
            $table->timestampTz('submitted_at')->nullable();

            $table->unsignedSmallInteger('item_count')->default(0);
            $table->unsignedSmallInteger('answered_count')->default(0);
            $table->unsignedSmallInteger('correct_count')->nullable();  // calculé à la soumission

            $table->timestampsTz();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['tenant_id', 'user_id', 'created_at']);
        });

        DB::statement('ALTER TABLE attempts ADD COLUMN kind attempt_kind NOT NULL');
        DB::statement("ALTER TABLE attempts ADD COLUMN status attempt_status NOT NULL DEFAULT 'in_progress'");
        DB::statement("ALTER TABLE attempts ADD CONSTRAINT attempts_locale_supported CHECK (locale IN ('fr','ar'))");

        // Une seule tentative de diagnostic en cours par candidat et par épreuve :
        // sinon un candidat pourrait ouvrir dix diagnostics et garder le meilleur.
        DB::statement(
            "CREATE UNIQUE INDEX attempts_single_open_diagnostic
             ON attempts (user_id, exam_id)
             WHERE kind = 'diagnostic' AND status = 'in_progress'"
        );

        Schema::create('attempt_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('attempt_id')->constrained()->cascadeOnDelete();

            /* restrictOnDelete : une question présentée ne peut plus être
             * supprimée. Elle se retire (statut), elle ne s'efface pas. */
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->foreignId('competency_node_id')->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('position');
            $table->timestampTz('presented_at')->nullable();
            $table->timestampsTz();

            $table->unique(['attempt_id', 'position']);
            $table->unique(['attempt_id', 'question_id']);   // jamais deux fois la même
            $table->index(['tenant_id', 'attempt_id']);
        });

        Schema::create('responses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();

            // Une réponse par item : l'unicité empêche tout doublon concurrent.
            $table->foreignId('attempt_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('selected_option_id')->nullable()->constrained('question_options')->restrictOnDelete();

            $table->boolean('is_correct')->nullable();       // figé à la soumission

            /* Deux horloges. `answered_at` fait foi ; `client_reported_at` sert
             * à repérer les dérives, jamais à décider. */
            $table->timestampTz('answered_at');
            $table->timestampTz('client_reported_at')->nullable();
            $table->unsignedInteger('elapsed_ms')->nullable();

            $table->boolean('cause_revealed')->default(false);  // décompte du quota gratuit
            $table->timestampsTz();

            $table->index(['tenant_id', 'answered_at']);
        });

        DB::statement('ALTER TABLE responses ADD COLUMN confidence answer_confidence');

        /* Compteur cumulatif de causes révélées, par candidat.
         * Fiche F03 : deux causes en compte gratuit, jamais remis à zéro —
         * un compteur quotidien cesserait d'inciter à l'abonnement. */
        Schema::create('cause_reveal_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revealed_total')->default(0);
            $table->timestampTz('first_revealed_at')->nullable();
            $table->timestampTz('last_revealed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cause_reveal_counters');
        Schema::dropIfExists('responses');
        Schema::dropIfExists('attempt_items');
        Schema::dropIfExists('attempts');

        foreach (['answer_confidence', 'attempt_status', 'attempt_kind'] as $type) {
            DB::statement("DROP TYPE IF EXISTS {$type}");
        }
    }
};
