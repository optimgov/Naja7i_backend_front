<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F07 — Rendez-vous Mémoire : le calendrier de répétition espacée.
 *
 * CE QUI EST PLANIFIÉ N'EST PAS UNE QUESTION, C'EST UNE ERREUR.
 *
 * La ligne porte le couple (compétence, cause), pas `question_id`. C'est la
 * décision structurante de la fiche, et elle vient de l'ADN du produit :
 * comprendre la cause, pas retenir la réponse. Resservir douze fois le même
 * item apprend l'item — le candidat reconnaît l'énoncé et non le raisonnement.
 *
 * `last_question_id` n'est donc pas la clé du rendez-vous : c'est la trace de
 * ce qui a été servi la dernière fois, pour éviter de resservir la même sœur
 * deux fois de suite. C'est aussi le point de branchement de F05 (question
 * miroir) le jour où elle arrivera : il n'y aura qu'à changer le sélecteur.
 *
 * Table d'ACTIVITÉ : isolée par tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_schedules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('competency_node_id')->constrained()->restrictOnDelete();

            /* Dernière question servie sur ce rendez-vous. Trace, pas clé :
             * `nullOnDelete` parce qu'une question retirée ne doit pas
             * emporter le rendez-vous — l'erreur reste à travailler. */
            $table->foreignId('last_question_id')->nullable()
                ->constrained('questions')->nullOnDelete();

            /* Palier du casier, de 1 à 5. La valeur est un INDEX dans la table
             * des intervalles, pas un nombre de jours : elle survit à un
             * réétalonnage des paliers. */
            $table->unsignedSmallInteger('palier')->default(1);

            /* Date d'échéance, pas horodatage : la frontière de journée est
             * celle du candidat (config naja7i.timezone_candidat), et une heure
             * précise n'aurait aucun sens pour une révision quotidienne. */
            $table->date('due_on');

            /* Deux `sure` consécutifs depuis le dernier échec font sortir du
             * calendrier. Sans porte de sortie, la liste grossit indéfiniment
             * et le candidat finit noyé. */
            $table->unsignedSmallInteger('consecutive_sure')->default(0);

            /* Erreur commise AVEC certitude : le candidat ne sait pas qu'il ne
             * sait pas. Même logique que FACTEUR_ERREUR_AVEUGLE de
             * RemediationPlanner — elle remonte en tête de la liste du jour. */
            $table->boolean('blind_error')->default(false);

            $table->timestampTz('last_reviewed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'user_id', 'due_on']);
        });

        // La cause est l'autre moitié de la clé métier. Type partagé avec
        // question_options : une cause de calendrier est une cause d'erreur.
        DB::statement('ALTER TABLE review_schedules ADD COLUMN cause error_cause NOT NULL');

        /* Un seul rendez-vous par (candidat, compétence, cause). Deux lignes
         * pour la même erreur feraient réviser deux fois la même chose le même
         * jour, et le candidat croirait avoir deux lacunes là où il en a une. */
        DB::statement(
            'CREATE UNIQUE INDEX review_schedules_unique_erreur
             ON review_schedules (tenant_id, user_id, competency_node_id, cause)'
        );

        DB::statement(
            'ALTER TABLE review_schedules ADD CONSTRAINT review_schedules_palier_range
             CHECK (palier BETWEEN 1 AND 5)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('review_schedules');
    }
};
