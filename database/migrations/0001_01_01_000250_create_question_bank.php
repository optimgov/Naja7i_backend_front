<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAS-5 — Banque de questions.
 *
 * Trois principes de conception, chacun issu d'une décision antérieure :
 *
 * 1. UNE QUESTION EST MONOLINGUE. Le référentiel CRMEF est explicite : pour
 *    l'épreuve de sciences de l'éducation, la version française et la version
 *    arabe sont deux contenus éditoriaux DISTINCTS, pas une traduction. Des
 *    colonnes `stem_fr` / `stem_ar` auraient forcé la traduction et perdu
 *    cette distinction. Deux questions liées par `sibling_group` plutôt qu'une
 *    question bilingue.
 *
 * 2. LA CAUSE EST UNE DONNÉE ÉDITORIALE, PAS UNE INFÉRENCE (fiche F03). Chaque
 *    distracteur porte son code de cause. Une question ne peut pas être
 *    éligible au diagnostic si un seul distracteur n'est pas étiqueté — et
 *    c'est un trigger qui l'impose, pas une consigne.
 *
 * 3. LA SOURCE DU BLUEPRINT NE PROUVE PAS LA BONNE RÉPONSE (ADR-0014 §6). Un
 *    descriptif officiel établit le périmètre et les poids. Il n'établit pas
 *    qu'une réponse est juste. D'où une table de sources de CONTENU, séparée,
 *    avec son propre statut de vérification.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE TYPE question_status AS ENUM (
                'draft', 'a_verifier', 'reviewed', 'pedagogically_validated', 'published', 'retired'
            )"
        );
        DB::statement("CREATE TYPE question_kind AS ENUM ('qcm_single', 'qcm_multiple', 'true_false')");
        DB::statement("CREATE TYPE authoring_mode AS ENUM ('human', 'ai_assisted', 'imported')");
        DB::statement(
            "CREATE TYPE error_cause AS ENUM (
                'confusion_notions', 'lecture_enonce', 'regle_mal_appliquee', 'connaissance_absente',
                'source_perimee', 'calcul', 'piege_formulation', 'indetermine'
            )"
        );
        DB::statement("CREATE TYPE content_verification AS ENUM ('unverified', 'verified', 'disputed')");

        // --- Remédiations ---------------------------------------------------
        Schema::create('remediations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('competency_node_id')->constrained()->restrictOnDelete();
            $table->string('locale', 2);
            $table->string('title');
            $table->text('content');
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->timestampsTz();

            $table->index(['competency_node_id', 'locale']);
        });

        DB::statement("ALTER TABLE remediations ADD COLUMN status question_status NOT NULL DEFAULT 'draft'");

        // --- Questions ------------------------------------------------------
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('competency_node_id')->constrained()->restrictOnDelete();

            $table->string('locale', 2);

            /* Regroupe les versions linguistiques d'une même intention
             * éditoriale, sans les rendre identiques. Deux questions du même
             * groupe couvrent la même notion, avec des contenus propres. */
            $table->uuid('sibling_group')->nullable()->index();

            $table->text('stem');                       // l'énoncé
            $table->text('explanation')->nullable();    // justification de la bonne réponse
            $table->unsignedSmallInteger('difficulty')->nullable();     // 1..5, jamais deviné
            $table->string('cognitive_level')->nullable();              // restitution, application…

            /* Versionnement : une question publiée ne se modifie pas. Une
             * correction crée une nouvelle version, l'ancienne est retirée.
             * Les tentatives passées continuent de pointer vers la version
             * réellement présentée au candidat. */
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('questions')->nullOnDelete();

            /* Question miroir : un OBJET LIÉ, pas du texte enfoui dans une
             * justification. Elle vérifie le transfert sur la même notion. */
            $table->foreignId('mirror_question_id')->nullable()->constrained('questions')->nullOnDelete();

            $table->foreignId('remediation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('delayed_review_days')->nullable();

            $table->boolean('eligible_for_diagnostic')->default(false);
            $table->boolean('eligible_for_simulation')->default(false);

            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validator_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->timestampsTz();

            $table->index(['exam_id', 'competency_node_id']);
            $table->index(['exam_id', 'locale']);
        });

        DB::statement("ALTER TABLE questions ADD COLUMN status question_status NOT NULL DEFAULT 'draft'");
        DB::statement("ALTER TABLE questions ADD COLUMN kind question_kind NOT NULL DEFAULT 'qcm_single'");
        DB::statement("ALTER TABLE questions ADD COLUMN authoring authoring_mode NOT NULL DEFAULT 'human'");
        DB::statement(
            "ALTER TABLE questions ADD CONSTRAINT questions_locale_supported
             CHECK (locale IN ('fr', 'ar'))"
        );
        DB::statement(
            'ALTER TABLE questions ADD CONSTRAINT questions_difficulty_range
             CHECK (difficulty IS NULL OR difficulty BETWEEN 1 AND 5)'
        );
        DB::statement(
            'ALTER TABLE questions ADD CONSTRAINT questions_not_own_mirror
             CHECK (mirror_question_id IS NULL OR mirror_question_id <> id)'
        );

        // --- Options ---------------------------------------------------------
        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('content');
            $table->boolean('is_correct')->default(false);

            /* Justification OBLIGATOIRE, y compris pour la bonne réponse.
             * « Pourquoi pas B ? » est la fonction F04 : sans justification par
             * option, elle n'existe pas. */
            $table->text('rationale');

            $table->timestampsTz();

            $table->unique(['question_id', 'position']);
        });

        DB::statement('ALTER TABLE question_options ADD COLUMN cause error_cause');

        // Exactement une bonne réponse par question, garanti par la base.
        DB::statement(
            'CREATE UNIQUE INDEX question_options_single_correct
             ON question_options (question_id) WHERE is_correct'
        );

        // Une justification vide n'est pas une justification.
        DB::statement(
            'ALTER TABLE question_options ADD CONSTRAINT question_options_rationale_not_empty
             CHECK (length(btrim(rationale)) > 0)'
        );

        // Un distracteur peut porter une cause ; la bonne réponse, jamais.
        DB::statement(
            'ALTER TABLE question_options ADD CONSTRAINT question_options_cause_on_distractors
             CHECK (NOT is_correct OR cause IS NULL)'
        );

        // --- Sources de contenu ------------------------------------------------
        Schema::create('question_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->restrictOnDelete();
            $table->string('locator')->nullable();      // page, article, chapitre
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->unique(['question_id', 'source_id']);
        });

        DB::statement(
            "ALTER TABLE question_sources ADD COLUMN verification content_verification NOT NULL DEFAULT 'unverified'"
        );

        $this->gardeDiagnostic();
    }

    /**
     * Fiche F03, règle d'obligation : une question ne peut pas être éligible au
     * diagnostic si un seul de ses distracteurs n'est pas étiqueté.
     *
     * Cette garde est en BASE et non dans un contrôleur, pour la raison
     * donnée dans la fiche : une règle éditoriale seulement documentée est
     * contournée le premier jour où la production presse.
     *
     * Elle se déclenche des deux côtés — quand on rend une question éligible,
     * et quand on retire une étiquette à une question déjà éligible.
     */
    private function gardeDiagnostic(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_diagnostic_eligibility()
            RETURNS TRIGGER AS $$
            DECLARE
                cible bigint;
                distracteurs_sans_cause int;
                total_options int;
                eligible boolean;
            BEGIN
                IF TG_TABLE_NAME = 'questions' THEN
                    cible := NEW.id;
                    eligible := NEW.eligible_for_diagnostic;
                ELSE
                    cible := COALESCE(NEW.question_id, OLD.question_id);
                    SELECT q.eligible_for_diagnostic INTO eligible
                    FROM questions q WHERE q.id = cible;
                END IF;

                IF eligible IS NOT TRUE THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;

                SELECT count(*) INTO total_options
                FROM question_options WHERE question_id = cible;

                SELECT count(*) INTO distracteurs_sans_cause
                FROM question_options
                WHERE question_id = cible AND NOT is_correct AND cause IS NULL;

                IF total_options > 0 AND distracteurs_sans_cause > 0 THEN
                    RAISE EXCEPTION
                        'Une question éligible au diagnostic exige une cause sur chaque distracteur (fiche F03). % distracteur(s) non étiqueté(s).',
                        distracteurs_sans_cause;
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER questions_diagnostic_guard
                AFTER INSERT OR UPDATE ON questions
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION assert_diagnostic_eligibility();

            CREATE CONSTRAINT TRIGGER question_options_diagnostic_guard
                AFTER INSERT OR UPDATE OR DELETE ON question_options
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION assert_diagnostic_eligibility();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS question_options_diagnostic_guard ON question_options');
        DB::unprepared('DROP TRIGGER IF EXISTS questions_diagnostic_guard ON questions');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_diagnostic_eligibility()');

        Schema::dropIfExists('question_sources');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('remediations');

        foreach (['content_verification', 'error_cause', 'authoring_mode', 'question_kind', 'question_status'] as $type) {
            DB::statement("DROP TYPE IF EXISTS {$type}");
        }
    }
};
