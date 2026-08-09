<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAS-7 — Maîtrise par compétence.
 *
 * Règle fondatrice (R04) : AUCUN SCORE SANS SON VOLUME D'ÉVIDENCE.
 *
 * Un taux de 100 % sur deux questions et un taux de 100 % sur trente ne disent
 * pas la même chose. Afficher les deux de la même façon serait la manière la
 * plus efficace de tromper un candidat tout en ayant l'air rigoureux — et
 * c'est exactement ce que font les plateformes qu'on cherche à dépasser.
 *
 * D'où trois colonnes indissociables : le score, le nombre de réponses qui le
 * fondent, et le niveau d'évidence qui en découle. Le score n'est jamais
 * exposé sans les deux autres.
 *
 * Table d'ACTIVITÉ : isolée par tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE evidence_level AS ENUM ('insufficient', 'low', 'sufficient')");

        Schema::create('mastery_scores', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('competency_node_id')->constrained()->restrictOnDelete();

            /* Score de 0 à 100, pondéré par la certitude déclarée.
             * NUL tant que l'évidence est insuffisante : mieux vaut ne rien
             * afficher qu'afficher un chiffre que le candidat croira. */
            $table->decimal('score', 5, 2)->nullable();

            $table->unsignedSmallInteger('answered_count')->default(0);
            $table->unsignedSmallInteger('correct_count')->default(0);

            /* Réponses justes déclarées « au hasard ». Elles gonflent le taux
             * brut sans rien prouver : comptées à part pour rester visibles. */
            $table->unsignedSmallInteger('lucky_guess_count')->default(0);

            /* Réponses fausses déclarées « sûr ». L'erreur la plus coûteuse :
             * le candidat ne sait pas qu'il ne sait pas. Prioritaire en
             * remédiation, quel que soit le score. */
            $table->unsignedSmallInteger('confident_error_count')->default(0);

            $table->timestampTz('last_answered_at')->nullable();
            $table->timestampTz('computed_at');
            $table->timestampsTz();

            $table->unique(['user_id', 'competency_node_id']);
            $table->index(['tenant_id', 'user_id', 'exam_id']);
        });

        DB::statement(
            "ALTER TABLE mastery_scores ADD COLUMN evidence evidence_level NOT NULL DEFAULT 'insufficient'"
        );
        DB::statement(
            'ALTER TABLE mastery_scores ADD CONSTRAINT mastery_scores_range
             CHECK (score IS NULL OR (score >= 0 AND score <= 100))'
        );

        // Un score ne peut pas exister sans évidence suffisante : la règle R04
        // tenue par la base, pas seulement par le service qui la calcule.
        DB::statement(
            "ALTER TABLE mastery_scores ADD CONSTRAINT mastery_scores_no_score_without_evidence
             CHECK (score IS NULL OR evidence <> 'insufficient')"
        );

        DB::statement(
            'ALTER TABLE mastery_scores ADD CONSTRAINT mastery_scores_counts_coherent
             CHECK (correct_count <= answered_count AND lucky_guess_count <= correct_count)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('mastery_scores');
        DB::statement('DROP TYPE IF EXISTS evidence_level');
    }
};
