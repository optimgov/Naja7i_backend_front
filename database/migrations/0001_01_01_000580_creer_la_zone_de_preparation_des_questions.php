<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Q2 — Zone de préparation des questions.
 *
 * Cette zone est une file de travail du catalogue global, jamais une banque :
 * elle ne porte ni publication, ni éligibilité candidat, ni mesure d'usage.
 * Le futur transfert créera un brouillon dans `questions`; il n'est pas livré
 * par cette migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE question_preparation_batch_status AS ENUM ('in_progress', 'completed', 'interrupted')");
        DB::statement(
            "CREATE TYPE prepared_question_state AS ENUM (
                'imported', 'qualified', 'answered', 'transferred',
                'illegible', 'duplicate', 'rejected', 'replaced'
            )"
        );
        DB::statement(
            "CREATE TYPE question_preparation_event_type AS ENUM (
                'assignment_changed', 'qualification_changed', 'difficulty_changed',
                'answer_confirmed', 'marked_duplicate', 'marked_illegible', 'rejected'
            )"
        );

        Schema::create('question_preparation_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source_path', 1024);
            $table->char('sha256', 64);
            $table->jsonb('counts')->default(DB::raw("'{}'::jsonb"));
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique('sha256', 'question_preparation_batches_sha_unique');
        });

        DB::statement(
            "ALTER TABLE question_preparation_batches
             ADD COLUMN status question_preparation_batch_status NOT NULL DEFAULT 'in_progress'"
        );
        DB::statement(
            'ALTER TABLE question_preparation_batches
             ADD CONSTRAINT question_preparation_batches_sha256_format
             CHECK (sha256 ~ \'^[0-9a-f]{64}$\')'
        );
        DB::statement(
            "ALTER TABLE question_preparation_batches
             ADD CONSTRAINT question_preparation_batches_finish_consistent
             CHECK ((status = 'in_progress' AND finished_at IS NULL)
                 OR (status <> 'in_progress' AND finished_at IS NOT NULL))"
        );

        Schema::create('prepared_questions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('batch_id')->constrained('question_preparation_batches')->restrictOnDelete();
            $table->string('import_ref', 255);
            $table->char('source_sha256', 64);

            /* Trois zones sans recouvrement : faits immuables, hypothèses
             * provisoires, puis saisie humaine. `statut` est interdit dans la
             * seconde : il est mappé vers `state`. */
            $table->jsonb('source_facts');
            $table->jsonb('provisional')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('human_fields')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('anomalies')->default(DB::raw("'[]'::jsonb"));

            $table->unsignedSmallInteger('provisional_difficulty')->nullable();
            $table->unsignedSmallInteger('declared_difficulty')->nullable();
            $table->string('proposed_answer', 1)->nullable();
            $table->string('confirmed_answer', 1)->nullable();

            /* Un domaine n'est jamais fabriqué. Il reste nul jusqu'au geste
             * d'un humain identifié. */
            $table->foreignId('competency_node_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('assigned_at')->nullable();
            $table->foreignId('qualified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('qualified_at')->nullable();
            $table->foreignId('difficulty_set_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('difficulty_set_at')->nullable();
            $table->foreignId('answer_confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('answer_confirmed_at')->nullable();

            /* `supersedes_ref` identifie sans ambiguïté l'UUID de la ligne
             * remplacée. `duplicate_of_ref` conserve le rattachement sans
             * jamais transférer le doublon. */
            $table->uuid('supersedes_ref')->nullable();
            $table->uuid('duplicate_of_ref')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('question_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['batch_id', 'active']);

        });

        /* Les auto-références sont posées après la création : PostgreSQL doit
         * déjà voir l'unicité de uuid pour accepter la cible de la FK. */
        Schema::table('prepared_questions', function (Blueprint $table) {
            $table->foreign('supersedes_ref', 'prepared_questions_supersedes_fk')
                ->references('uuid')
                ->on('prepared_questions')
                ->restrictOnDelete();
            $table->foreign('duplicate_of_ref', 'prepared_questions_duplicate_fk')
                ->references('uuid')
                ->on('prepared_questions')
                ->restrictOnDelete();
        });

        DB::statement(
            "ALTER TABLE prepared_questions
             ADD COLUMN state prepared_question_state NOT NULL DEFAULT 'imported'"
        );
        DB::statement('CREATE INDEX prepared_questions_state_idx ON prepared_questions (state)');
        DB::statement(
            'CREATE UNIQUE INDEX prepared_questions_active_import_ref_unique
             ON prepared_questions (import_ref) WHERE active'
        );
        DB::statement(
            'ALTER TABLE prepared_questions
             ADD CONSTRAINT prepared_questions_source_sha256_format
             CHECK (source_sha256 ~ \'^[0-9a-f]{64}$\')'
        );
        DB::statement(
            "ALTER TABLE prepared_questions
             ADD CONSTRAINT prepared_questions_provisional_has_no_status
             CHECK (NOT jsonb_exists(provisional, 'statut'))"
        );
        DB::statement(
            'ALTER TABLE prepared_questions
             ADD CONSTRAINT prepared_questions_difficulty_range
             CHECK ((provisional_difficulty IS NULL OR provisional_difficulty BETWEEN 1 AND 5)
                AND (declared_difficulty IS NULL OR declared_difficulty BETWEEN 1 AND 5))'
        );
        DB::statement(
            "ALTER TABLE prepared_questions
             ADD CONSTRAINT prepared_questions_answers_supported
             CHECK ((proposed_answer IS NULL OR proposed_answer IN ('A', 'B', 'C', 'D', 'E'))
                AND (confirmed_answer IS NULL OR confirmed_answer IN ('A', 'B', 'C', 'D', 'E')))"
        );
        DB::statement(
            'ALTER TABLE prepared_questions
             ADD CONSTRAINT prepared_questions_assignment_trace
             CHECK ((assigned_to IS NULL AND assigned_at IS NULL)
                OR (assigned_to IS NOT NULL AND assigned_at IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE prepared_questions
             ADD CONSTRAINT prepared_questions_qualification_trace
             CHECK ((competency_node_id IS NULL AND qualified_by IS NULL AND qualified_at IS NULL)
                OR (competency_node_id IS NOT NULL AND qualified_by IS NOT NULL AND qualified_at IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE prepared_questions
             ADD CONSTRAINT prepared_questions_difficulty_trace
             CHECK ((declared_difficulty IS NULL AND difficulty_set_by IS NULL AND difficulty_set_at IS NULL)
                OR (declared_difficulty IS NOT NULL AND difficulty_set_by IS NOT NULL AND difficulty_set_at IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE prepared_questions
             ADD CONSTRAINT prepared_questions_answer_trace
             CHECK ((confirmed_answer IS NULL AND answer_confirmed_by IS NULL AND answer_confirmed_at IS NULL)
                OR (confirmed_answer IS NOT NULL AND answer_confirmed_by IS NOT NULL AND answer_confirmed_at IS NOT NULL))'
        );
        DB::statement(
            "ALTER TABLE prepared_questions
             ADD CONSTRAINT prepared_questions_state_payload_consistent
             CHECK ((state <> 'qualified' OR competency_node_id IS NOT NULL)
                AND (state <> 'answered' OR (competency_node_id IS NOT NULL AND confirmed_answer IS NOT NULL))
                AND ((state = 'duplicate' AND duplicate_of_ref IS NOT NULL)
                    OR (state <> 'duplicate' AND duplicate_of_ref IS NULL))
                AND ((state = 'transferred' AND question_id IS NOT NULL)
                    OR (state <> 'transferred' AND question_id IS NULL))
                AND ((state = 'replaced' AND active IS FALSE)
                    OR (state <> 'replaced' AND active IS TRUE))
                AND (supersedes_ref IS NULL OR active IS TRUE))"
        );
        DB::statement(
            'ALTER TABLE prepared_questions
             ADD CONSTRAINT prepared_questions_not_self_referential
             CHECK ((supersedes_ref IS NULL OR supersedes_ref <> uuid)
                AND (duplicate_of_ref IS NULL OR duplicate_of_ref <> uuid))'
        );

        Schema::create('question_preparation_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('prepared_question_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->jsonb('before')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('after')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['prepared_question_id', 'occurred_at'], 'question_preparation_events_timeline_idx');
        });
        DB::statement(
            'ALTER TABLE question_preparation_events
             ADD COLUMN event_type question_preparation_event_type NOT NULL'
        );
        DB::statement(
            "ALTER TABLE question_preparation_events
             ADD CONSTRAINT question_preparation_events_payload_objects
             CHECK (jsonb_typeof(before) = 'object' AND jsonb_typeof(after) = 'object')"
        );

        $this->protectPreparationFacts();
        $this->protectPreparationEvents();
    }

    private function protectPreparationFacts(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_prepared_question_integrity()
            RETURNS TRIGGER AS $$
            BEGIN
                IF OLD.source_facts IS DISTINCT FROM NEW.source_facts
                   OR OLD.source_sha256 IS DISTINCT FROM NEW.source_sha256
                   OR OLD.import_ref IS DISTINCT FROM NEW.import_ref
                   OR OLD.batch_id IS DISTINCT FROM NEW.batch_id
                   OR OLD.proposed_answer IS DISTINCT FROM NEW.proposed_answer THEN
                    RAISE EXCEPTION 'Les faits de source et leur rattachement sont immuables dans la zone de préparation.';
                END IF;

                IF OLD.state = 'transferred' AND NEW IS DISTINCT FROM OLD THEN
                    RAISE EXCEPTION 'Une ligne transférée est une trace en lecture seule.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER prepared_questions_integrity
                BEFORE UPDATE ON prepared_questions
                FOR EACH ROW EXECUTE FUNCTION assert_prepared_question_integrity();

            CREATE OR REPLACE FUNCTION refuse_prepared_question_deletion()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Une ligne de préparation est une trace et ne se supprime jamais.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER prepared_questions_no_delete
                BEFORE DELETE ON prepared_questions
                FOR EACH ROW EXECUTE FUNCTION refuse_prepared_question_deletion();
        SQL);
    }

    private function protectPreparationEvents(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_question_preparation_event_mutation()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Le journal de préparation est append-only.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER question_preparation_events_append_only
                BEFORE UPDATE OR DELETE ON question_preparation_events
                FOR EACH ROW EXECUTE FUNCTION refuse_question_preparation_event_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS question_preparation_events_append_only ON question_preparation_events');
        DB::unprepared('DROP FUNCTION IF EXISTS refuse_question_preparation_event_mutation()');
        Schema::dropIfExists('question_preparation_events');
        DB::unprepared('DROP TRIGGER IF EXISTS prepared_questions_no_delete ON prepared_questions');
        DB::unprepared('DROP FUNCTION IF EXISTS refuse_prepared_question_deletion()');
        DB::unprepared('DROP TRIGGER IF EXISTS prepared_questions_integrity ON prepared_questions');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_prepared_question_integrity()');
        Schema::dropIfExists('prepared_questions');
        Schema::dropIfExists('question_preparation_batches');
        DB::statement('DROP TYPE IF EXISTS prepared_question_state');
        DB::statement('DROP TYPE IF EXISTS question_preparation_event_type');
        DB::statement('DROP TYPE IF EXISTS question_preparation_batch_status');
    }
};
