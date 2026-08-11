<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'acquisition d'une cause devient une LIGNE, et non plus une déduction.
 *
 * AUDIT TOURNÉE 2, BLOC-2. Depuis le PAS-26, l'unité de quota achète un couple
 * (compétence, cause) et non une réponse. Mais l'acquisition n'existait nulle
 * part : `CauseRevealService` la DÉDUISAIT par une jointure sur les réponses
 * déjà révélées, hors transaction, avant de réserver.
 *
 * Un acquis déduit par requête ne peut pas être atomique. Deux révélations
 * concurrentes du même couple, portées par deux réponses différentes, lisaient
 * toutes deux « pas encore acquis » et consommaient chacune une unité : le
 * plafond restait atomique — il l'est depuis le PAS-10 — mais la NOUVELLE
 * unité, elle, ne l'était pas. Un candidat gratuit pouvait payer deux fois la
 * même cause, ou en obtenir trois pour deux unités selon l'entrelacement.
 *
 * Un acquis qui EXISTE EN BASE l'est par construction : l'index unique
 * ci-dessous arbitre, et la seconde transaction constate au lieu de réserver.
 * C'est le même déplacement qu'au PAS-10 BLOC-3 — verrouiller la ressource
 * rare plutôt que l'objet qui la consomme.
 *
 * Table d'ACTIVITÉ : isolée par tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cause_acquisitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('competency_node_id')->constrained()->restrictOnDelete();

            /* La réponse qui a déclenché l'acquisition. TRACE, jamais clé : le
             * couple est acquis pour toutes les réponses, présentes et à venir.
             * `nullOnDelete` — effacer une tentative n'annule pas un achat. */
            $table->foreignId('response_id')->nullable()->constrained()->nullOnDelete();

            /* Vrai quand l'acquisition n'a consommé aucune unité : accès
             * illimité. Distinguer les deux permet de dire au candidat ce qu'il
             * a payé, et de recompter si le plafond change. */
            $table->boolean('granted_by_access')->default(false);

            $table->timestampTz('acquired_at');
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE cause_acquisitions ADD COLUMN cause error_cause NOT NULL');

        /* LA GARANTIE. Une cause s'acquiert une fois par candidat et par
         * compétence. C'est cet index qui rend la réservation atomique : la
         * seconde transaction bute dessus et lit l'acquisition de la première
         * au lieu d'en payer une seconde. */
        DB::statement(
            'CREATE UNIQUE INDEX cause_acquisitions_unique
             ON cause_acquisitions (tenant_id, user_id, competency_node_id, cause)'
        );

        /*
         * REPRISE DE L'EXISTANT. Les révélations déjà payées vivaient dans
         * `responses.cause_revealed` ; sans reprise, un candidat repaierait
         * demain ce qu'il a payé hier — et le compteur, lui, ne se remet jamais
         * à zéro. `DISTINCT ON` retient la PREMIÈRE révélation de chaque
         * couple, celle qui l'a effectivement payé.
         */
        DB::statement(
            'INSERT INTO cause_acquisitions
                 (uuid, tenant_id, user_id, competency_node_id, response_id,
                  cause, granted_by_access, acquired_at, created_at, updated_at)
             SELECT DISTINCT ON (r.tenant_id, a.user_id, ai.competency_node_id, o.cause)
                    gen_random_uuid(), r.tenant_id, a.user_id, ai.competency_node_id,
                    r.id, o.cause, false, r.updated_at, now(), now()
               FROM responses r
               JOIN attempt_items ai ON ai.id = r.attempt_item_id
               JOIN attempts a ON a.id = ai.attempt_id
               JOIN question_options o ON o.id = r.selected_option_id
              WHERE r.cause_revealed = true AND o.cause IS NOT NULL
              ORDER BY r.tenant_id, a.user_id, ai.competency_node_id, o.cause, r.id'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cause_acquisitions');
    }
};
