<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PAS-10 — Correctifs de la revue du 9 août.
 *
 * Quatre familles de défauts, toutes de la même nature : une règle énoncée
 * dans un ADR, détectable par un service, mais qu'aucune contrainte
 * n'imposait. C'est l'écart G10 — celui que la méthode du projet prétend
 * traquer — commis dans les lots censés le fermer.
 *
 * 1. Unicités ignorant le tenant (PAS-6, PAS-7)
 * 2. Historique juridique modifiable (PAS-2)
 * 3. Contenu publié modifiable sur place (PAS-5)
 * 4. Consommation de jeton et de quota non atomiques (PAS-3, PAS-6)
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->unicitesTenantAware();
        $this->historiqueJuridiqueImmuable();
        $this->contenuPublieImmuable();
    }

    /**
     * REVUE PAS-6 BLOC-1 et PAS-7 BLOC-1.
     *
     * Ces tables portent `tenant_id` mais leurs unicités l'ignoraient. Un même
     * compte, membre de deux organismes, se heurtait à l'index de l'un depuis
     * l'autre — une ligne invisible sous le scope courant bloquait une
     * insertion légitime, et l'isolation produisait une erreur 500 au lieu de
     * données indépendantes.
     *
     * Le défaut n'est pas exploitable aujourd'hui : un seul tenant existe. Il
     * le deviendrait au premier contrat B2B, c'est-à-dire au pire moment.
     */
    private function unicitesTenantAware(): void
    {
        // --- attempts -----------------------------------------------------
        DB::statement('ALTER TABLE attempts DROP CONSTRAINT IF EXISTS attempts_user_id_idempotency_key_unique');
        DB::statement('DROP INDEX IF EXISTS attempts_user_id_idempotency_key_unique');
        DB::statement(
            'CREATE UNIQUE INDEX attempts_tenant_user_idempotency_unique
             ON attempts (tenant_id, user_id, idempotency_key)'
        );

        DB::statement('DROP INDEX IF EXISTS attempts_single_open_diagnostic');
        DB::statement(
            "CREATE UNIQUE INDEX attempts_single_open_diagnostic
             ON attempts (tenant_id, user_id, exam_id)
             WHERE kind = 'diagnostic' AND status = 'in_progress'"
        );

        // --- mastery_scores -----------------------------------------------
        DB::statement('ALTER TABLE mastery_scores DROP CONSTRAINT IF EXISTS mastery_scores_user_id_competency_node_id_unique');
        DB::statement('DROP INDEX IF EXISTS mastery_scores_user_id_competency_node_id_unique');
        DB::statement(
            'CREATE UNIQUE INDEX mastery_scores_tenant_user_node_unique
             ON mastery_scores (tenant_id, user_id, competency_node_id)'
        );
    }

    /**
     * REVUE PAS-2 BLOC-2.
     *
     * `LegalEvent` annonçait « jamais modifiée ni supprimée » dans sa
     * documentation, et exposait tous ses champs en assignation de masse.
     * Une commande de support pouvait réécrire une acceptation passée.
     *
     * Une preuve juridique altérable n'est pas une preuve.
     */
    private function historiqueJuridiqueImmuable(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_legal_event_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION
                        'Un acte juridique ne se supprime pas : il constitue la preuve opposable (ADR-0005).';
                END IF;

                RAISE EXCEPTION
                    'Un acte juridique ne se modifie pas. Un changement d''avis crée un nouvel acte (ADR-0005).';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER legal_events_append_only
                BEFORE UPDATE OR DELETE ON legal_events
                FOR EACH ROW EXECUTE FUNCTION assert_legal_event_append_only();
        SQL);
    }

    /**
     * REVUE PAS-5 BLOC-2.
     *
     * L'ADR-0015 §5 exige qu'une question publiée ne soit jamais modifiée :
     * une correction crée une version, l'ancienne est retirée. Rien ne
     * l'imposait.
     *
     * Le risque est précis : une tentative passée pointe vers la question,
     * pas vers un instantané de son contenu. Réécrire une question publiée
     * rend fausses les corrections déjà affichées à des candidats, et rend
     * tout historique de progression ininterprétable.
     *
     * Le changement de STATUT reste permis — c'est ainsi qu'on retire une
     * version. Seul le CONTENU est gelé.
     */
    private function contenuPublieImmuable(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_published_question_frozen()
            RETURNS TRIGGER AS $$
            BEGIN
                IF OLD.status <> 'published' THEN
                    RETURN NEW;
                END IF;

                IF NEW.stem               IS DISTINCT FROM OLD.stem
                OR NEW.explanation        IS DISTINCT FROM OLD.explanation
                OR NEW.competency_node_id IS DISTINCT FROM OLD.competency_node_id
                OR NEW.exam_id            IS DISTINCT FROM OLD.exam_id
                OR NEW.locale             IS DISTINCT FROM OLD.locale
                OR NEW.kind               IS DISTINCT FROM OLD.kind THEN
                    RAISE EXCEPTION
                        'Le contenu d''une question publiée est gelé. Créez une nouvelle version (ADR-0015 §5).';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER questions_published_frozen
                BEFORE UPDATE ON questions
                FOR EACH ROW EXECUTE FUNCTION assert_published_question_frozen();

            CREATE OR REPLACE FUNCTION assert_published_option_frozen()
            RETURNS TRIGGER AS $$
            DECLARE statut question_status;
            BEGIN
                SELECT status INTO statut FROM questions
                WHERE id = COALESCE(NEW.question_id, OLD.question_id);

                IF statut <> 'published' THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION
                        'Une option de question publiée ne se supprime pas (ADR-0015 §5).';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    RAISE EXCEPTION
                        'Aucune option ne s''ajoute à une question publiée (ADR-0015 §5).';
                END IF;

                IF NEW.content    IS DISTINCT FROM OLD.content
                OR NEW.is_correct IS DISTINCT FROM OLD.is_correct
                OR NEW.rationale  IS DISTINCT FROM OLD.rationale
                OR NEW.cause      IS DISTINCT FROM OLD.cause
                OR NEW.position   IS DISTINCT FROM OLD.position THEN
                    RAISE EXCEPTION
                        'Les options d''une question publiée sont gelées. Créez une nouvelle version (ADR-0015 §5).';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER question_options_published_frozen
                BEFORE INSERT OR UPDATE OR DELETE ON question_options
                FOR EACH ROW EXECUTE FUNCTION assert_published_option_frozen();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS question_options_published_frozen ON question_options');
        DB::unprepared('DROP TRIGGER IF EXISTS questions_published_frozen ON questions');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_published_option_frozen()');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_published_question_frozen()');
        DB::unprepared('DROP TRIGGER IF EXISTS legal_events_append_only ON legal_events');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_legal_event_append_only()');

        DB::statement('DROP INDEX IF EXISTS mastery_scores_tenant_user_node_unique');
        DB::statement('DROP INDEX IF EXISTS attempts_tenant_user_idempotency_unique');
        DB::statement('DROP INDEX IF EXISTS attempts_single_open_diagnostic');
    }
};
