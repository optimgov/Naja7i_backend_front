<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PAS-12 — Correctifs de la contre-revue PAS-11.
 *
 * Trois défauts, et un point commun : chaque garde protégeait UN MOMENT au
 * lieu de protéger un ÉTAT.
 *
 *  - Le trigger d'appartenance vérifiait le rôle à l'écriture de la
 *    `membership`. Rien n'empêchait d'attacher une permission réservée au rôle
 *    ENSUITE : les appartenances déjà créées ne repassent jamais dans le
 *    trigger.
 *  - Le gel refusait de modifier une question publiée, mais laissait sortir de
 *    l'état publié. Il suffisait de repasser en `draft` pour tout rouvrir.
 *  - Les options étaient gelées (PAS-10), les sources ne l'étaient pas. Or
 *    c'est la source vérifiée qui conditionne la publication : la retirer
 *    après coup invalide rétroactivement le contrôle.
 *
 * Règle qui s'en dégage : une garde placée sur une transition doit être
 * doublée d'une garde sur l'état, sinon il suffit de revenir en arrière.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->sortieDePublieVerrouillee();
        $this->sourcesGelees();
        $this->permissionsReserveesApresCoup();
    }

    /**
     * CONTRE-REVUE BLOC-3.
     *
     * Le gel excluait `status` de la comparaison — nécessaire pour permettre le
     * retrait — mais n'imposait aucune destination. `published → draft` passait,
     * et l'écriture suivante trouvait `OLD.status <> 'published'` : toutes les
     * colonnes redevenaient modifiables.
     *
     * Une question publiée ne sort plus que vers `retired`.
     */
    private function sortieDePublieVerrouillee(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS questions_published_frozen ON questions');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_published_question_frozen()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_published_question_frozen()
            RETURNS TRIGGER AS $$
            DECLARE avant jsonb; apres jsonb;
            BEGIN
                IF OLD.status <> 'published' THEN
                    RETURN NEW;
                END IF;

                -- Une seule sortie possible. Sans cette borne, il suffisait de
                -- repasser en brouillon pour rouvrir toutes les colonnes.
                IF NEW.status NOT IN ('published', 'retired') THEN
                    RAISE EXCEPTION
                        'Une question publiée ne peut que rester publiée ou être retirée (destination demandée : %). Créez une nouvelle version (ADR-0015 §5).',
                        NEW.status;
                END IF;

                IF NEW.status = 'retired' AND NEW.retired_at IS NULL THEN
                    RAISE EXCEPTION 'Un retrait doit être horodaté.';
                END IF;

                avant := to_jsonb(OLD) - 'status' - 'retired_at' - 'updated_at'
                                       - 'eligible_for_diagnostic' - 'eligible_for_simulation';
                apres := to_jsonb(NEW) - 'status' - 'retired_at' - 'updated_at'
                                       - 'eligible_for_diagnostic' - 'eligible_for_simulation';

                IF avant IS DISTINCT FROM apres THEN
                    RAISE EXCEPTION
                        'Une question publiée est gelée. Seuls le statut, le retrait et l''éligibilité changent (ADR-0015 §5).';
                END IF;

                IF (NEW.eligible_for_diagnostic AND NOT OLD.eligible_for_diagnostic)
                OR (NEW.eligible_for_simulation AND NOT OLD.eligible_for_simulation) THEN
                    RAISE EXCEPTION
                        'L''éligibilité d''une question publiée ne peut pas être élargie : republiez une nouvelle version.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER questions_published_frozen
                BEFORE UPDATE ON questions
                FOR EACH ROW EXECUTE FUNCTION assert_published_question_frozen();
        SQL);

        // Une question retirée ne revient jamais à un état modifiable non plus :
        // elle a été présentée à des candidats, son contenu doit rester lisible.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_retired_question_frozen()
            RETURNS TRIGGER AS $$
            DECLARE avant jsonb; apres jsonb;
            BEGIN
                IF OLD.status <> 'retired' THEN
                    RETURN NEW;
                END IF;

                IF NEW.status <> 'retired' THEN
                    RAISE EXCEPTION
                        'Une question retirée ne se réactive pas : créez une nouvelle version (ADR-0015 §5).';
                END IF;

                avant := to_jsonb(OLD) - 'updated_at';
                apres := to_jsonb(NEW) - 'updated_at';

                IF avant IS DISTINCT FROM apres THEN
                    RAISE EXCEPTION 'Une question retirée est gelée.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER questions_retired_frozen
                BEFORE UPDATE ON questions
                FOR EACH ROW EXECUTE FUNCTION assert_retired_question_frozen();
        SQL);
    }

    /**
     * CONTRE-REVUE BLOC-4, volet fondé.
     *
     * Les options étaient déjà gelées par le trigger du PAS-10
     * (`question_options_published_frozen`, migration 000300). Les SOURCES ne
     * l'étaient pas — alors que c'est précisément la source vérifiée qui
     * conditionne la publication d'une question de diagnostic.
     *
     * La retirer après coup invalidait rétroactivement le contrôle : la
     * question restait servie au candidat sans que rien ne fonde plus sa
     * bonne réponse.
     */
    private function sourcesGelees(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_published_sources_frozen()
            RETURNS TRIGGER AS $$
            DECLARE statut question_status;
            BEGIN
                SELECT status INTO statut FROM questions
                WHERE id = COALESCE(NEW.question_id, OLD.question_id);

                IF statut NOT IN ('published', 'retired') THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;

                RAISE EXCEPTION
                    'Les sources d''une question publiée sont gelées : elles fondent la correction déjà servie (ADR-0015 §5).';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER question_sources_published_frozen
                BEFORE INSERT OR UPDATE OR DELETE ON question_sources
                FOR EACH ROW EXECUTE FUNCTION assert_published_sources_frozen();
        SQL);
    }

    /**
     * CONTRE-REVUE BLOC-2.
     *
     * Le trigger du PAS-9 refusait d'attacher une permission réservée à un rôle
     * D'ORGANISME. Il ne disait rien d'un rôle GLOBAL — or un rôle global comme
     * `candidat` est attribué dans les organismes.
     *
     * Scénario : `candidat` est attribué dans dix organismes, puis
     * `tenants.manage` lui est attachée. Les appartenances existantes ne
     * repassent pas dans le trigger d'appartenance, et le résolveur accorde la
     * permission plateforme partout.
     *
     * Le contrôle porte désormais sur l'ÉTAT : une permission réservée ne
     * s'attache pas à un rôle qui possède une appartenance hors plateforme,
     * quel que soit l'ordre des opérations.
     */
    private function permissionsReserveesApresCoup(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS permission_role_scope_guard ON permission_role');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_permission_scope()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_permission_scope()
            RETURNS TRIGGER AS $$
            DECLARE
                reservee        boolean;
                code_permission text;
                role_tenant     bigint;
                code_role       text;
                hors_plateforme int;
            BEGIN
                SELECT platform_only, code INTO reservee, code_permission
                FROM permissions WHERE id = NEW.permission_id;

                IF NOT reservee THEN
                    RETURN NEW;
                END IF;

                SELECT tenant_id, code INTO role_tenant, code_role
                FROM roles WHERE id = NEW.role_id;

                -- Rôle d'organisme : jamais de permission réservée.
                IF role_tenant IS NOT NULL THEN
                    RAISE EXCEPTION
                        'La permission « % » est réservée à la plateforme : elle ne peut pas être attachée au rôle d''organisme « % ».',
                        code_permission, code_role;
                END IF;

                -- Rôle global déjà attribué hors plateforme : l'attacher
                -- maintenant escaladerait les appartenances existantes.
                SELECT count(*) INTO hors_plateforme
                FROM memberships WHERE role_id = NEW.role_id AND tenant_id <> 1;

                IF hors_plateforme > 0 THEN
                    RAISE EXCEPTION
                        'Le rôle « % » est attribué à % appartenance(s) hors plateforme : la permission réservée « % » ne peut pas lui être attachée.',
                        code_role, hors_plateforme, code_permission;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER permission_role_scope_guard
                BEFORE INSERT OR UPDATE ON permission_role
                FOR EACH ROW EXECUTE FUNCTION assert_permission_scope();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS question_sources_published_frozen ON question_sources');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_published_sources_frozen()');
        DB::unprepared('DROP TRIGGER IF EXISTS questions_retired_frozen ON questions');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_retired_question_frozen()');
    }
};
