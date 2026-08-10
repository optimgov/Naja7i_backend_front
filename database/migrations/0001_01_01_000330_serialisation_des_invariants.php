<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PAS-13 — Correctifs de la contre-revue PAS-12.
 *
 * Deux des trois blocants relèvent du même phénomène : l'ANOMALIE D'ÉCRITURE
 * sous READ COMMITTED, le niveau d'isolation par défaut de PostgreSQL.
 *
 *     T1 lit l'état, le juge conforme, écrit — sans valider.
 *     T2 lit l'état d'AVANT T1, le juge conforme, écrit.
 *     Les deux valident. L'état combiné est interdit, et aucun trigger ne
 *     l'a vu, parce qu'aucun n'a jamais observé l'autre.
 *
 * Un trigger qui lit sans verrou ne garantit rien sous concurrence : il
 * vérifie un passé, pas un présent. C'est un mode de défaillance différent de
 * ceux des trois revues précédentes — non plus « un chemin oublié », mais
 * « un ordre d'exécution possible ».
 *
 * Correction : chaque contrôle d'agrégat verrouille d'abord la ligne parente,
 * puis les lignes enfants. Toujours dans cet ordre, des deux côtés, pour que
 * deux transactions se sérialisent au lieu de s'interbloquer.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->gelDesEnfants();
        $this->publicationSerialisee();
        $this->roleSerialise();
    }

    /**
     * CONTRE-REVUE BLOC-1 — deux trous dans les gardes enfants.
     *
     * 1. Le trigger des options (PAS-10) ne se déclenchait que si le parent
     *    était `published`. Le PAS-12 a gelé la ligne `questions` en état
     *    `retired` sans mettre à jour ce trigger : les options d'une question
     *    retirée redevenaient modifiables. Or une question retirée a été
     *    présentée à des candidats — son contenu doit rester lisible tel qu'il
     *    a été vu.
     *
     * 2. Les deux gardes n'examinaient que `COALESCE(NEW.question_id,
     *    OLD.question_id)`. Sur un UPDATE de `question_id`, NEW l'emporte :
     *    déplacer une option d'une question publiée vers un brouillon ne
     *    contrôlait que le brouillon. La question gelée perdait une option
     *    sans qu'aucune ligne `questions` ne bouge.
     *
     * Désormais : les DEUX parents sont contrôlés, `published` et `retired`
     * sont gelés, et le parent est verrouillé avant lecture.
     */
    private function gelDesEnfants(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS question_options_published_frozen ON question_options');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_published_option_frozen()');
        DB::unprepared('DROP TRIGGER IF EXISTS question_sources_published_frozen ON question_sources');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_published_sources_frozen()');

        DB::unprepared(<<<'SQL'
            /*
             * Statut du parent, LU SOUS VERROU.
             * Le verrou est ce qui rend le contrôle valide sous concurrence :
             * sans lui, on lit un état que l'autre transaction est en train de
             * changer.
             */
            CREATE OR REPLACE FUNCTION statut_question_verrouille(question bigint)
            RETURNS question_status AS $$
            DECLARE statut question_status;
            BEGIN
                IF question IS NULL THEN
                    RETURN NULL;
                END IF;

                SELECT status INTO statut FROM questions WHERE id = question FOR UPDATE;

                RETURN statut;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION assert_question_child_frozen()
            RETURNS TRIGGER AS $$
            DECLARE
                parent_ancien bigint;
                parent_nouveau bigint;
                statut_ancien question_status;
                statut_nouveau question_status;
                objet text;
            BEGIN
                objet := CASE WHEN TG_TABLE_NAME = 'question_options' THEN 'option' ELSE 'source' END;

                parent_ancien  := CASE WHEN TG_OP <> 'INSERT' THEN OLD.question_id END;
                parent_nouveau := CASE WHEN TG_OP <> 'DELETE' THEN NEW.question_id END;

                /* Ordre de verrouillage stable : le plus petit identifiant
                 * d'abord. Deux transactions touchant les mêmes parents dans
                 * un ordre différent se sérialisent au lieu de s'interbloquer. */
                IF parent_ancien IS NOT NULL AND parent_nouveau IS NOT NULL
                   AND parent_ancien > parent_nouveau THEN
                    statut_nouveau := statut_question_verrouille(parent_nouveau);
                    statut_ancien  := statut_question_verrouille(parent_ancien);
                ELSE
                    statut_ancien  := statut_question_verrouille(parent_ancien);
                    statut_nouveau := statut_question_verrouille(parent_nouveau);
                END IF;

                -- L'ancien parent : on ne retire rien à une question gelée.
                IF statut_ancien IN ('published', 'retired') THEN
                    RAISE EXCEPTION
                        'Les % d''une question % sont gelées : elles fondent une correction déjà servie (ADR-0015 §5).',
                        objet || 's', statut_ancien;
                END IF;

                -- Le nouveau parent : on n'ajoute rien à une question gelée.
                IF statut_nouveau IN ('published', 'retired') THEN
                    RAISE EXCEPTION
                        'Aucune % ne s''ajoute ni ne se modifie sur une question % (ADR-0015 §5).',
                        objet, statut_nouveau;
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER question_options_frozen
                BEFORE INSERT OR UPDATE OR DELETE ON question_options
                FOR EACH ROW EXECUTE FUNCTION assert_question_child_frozen();

            CREATE TRIGGER question_sources_frozen
                BEFORE INSERT OR UPDATE OR DELETE ON question_sources
                FOR EACH ROW EXECUTE FUNCTION assert_question_child_frozen();
        SQL);
    }

    /**
     * CONTRE-REVUE BLOC-3 — publication et mutation des enfants non sérialisées.
     *
     * Le contrôle de publication comptait les options et les sources par
     * simples SELECT. Une transaction concurrente pouvait supprimer une option
     * pendant que la question était encore vue comme validée : chacune voyait
     * l'état d'avant l'autre, et la question se retrouvait publiée avec trois
     * options.
     *
     * Le contrôle verrouille désormais les lignes enfants. La ligne parente
     * l'est déjà par l'UPDATE lui-même — donc l'ordre parent → enfants est
     * respecté des deux côtés, et deux transactions se sérialisent.
     */
    private function publicationSerialisee(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_question_publishable()
            RETURNS TRIGGER AS $$
            DECLARE
                nb_options       int;
                nb_correctes     int;
                nb_sans_justif   int;
                nb_sans_cause    int;
                nb_sources_ok    int;
                statut_precedent question_status;
                ignore           bigint;
            BEGIN
                IF NEW.status <> 'published' THEN
                    RETURN NEW;
                END IF;

                statut_precedent := CASE WHEN TG_OP = 'UPDATE' THEN OLD.status ELSE NULL END;

                IF statut_precedent = 'published' THEN
                    RETURN NEW;
                END IF;

                IF TG_OP = 'INSERT' OR statut_precedent IS DISTINCT FROM 'pedagogically_validated' THEN
                    RAISE EXCEPTION
                        'Une question ne se publie que depuis l''état « validée pédagogiquement » (état actuel : %).',
                        COALESCE(statut_precedent::text, 'création directe');
                END IF;

                IF NEW.validator_id IS NULL THEN
                    RAISE EXCEPTION 'Publication refusée : aucun valideur enregistré.';
                END IF;

                IF NEW.validator_id = NEW.author_id THEN
                    RAISE EXCEPTION
                        'Publication refusée : le valideur ne peut pas être l''auteur (METHODE §7.2).';
                END IF;

                /* Verrou sur les enfants AVANT de les compter. Sans lui, une
                 * suppression concurrente non validée reste invisible et la
                 * question se publie sur un décompte périmé.
                 * La ligne parente est déjà verrouillée par l'UPDATE en cours :
                 * l'ordre parent → enfants est donc respecté. */
                PERFORM 1 FROM question_options WHERE question_id = NEW.id FOR UPDATE;
                PERFORM 1 FROM question_sources WHERE question_id = NEW.id FOR UPDATE;

                SELECT count(*),
                       count(*) FILTER (WHERE is_correct),
                       count(*) FILTER (WHERE length(btrim(rationale)) = 0),
                       count(*) FILTER (WHERE NOT is_correct AND cause IS NULL)
                INTO nb_options, nb_correctes, nb_sans_justif, nb_sans_cause
                FROM question_options WHERE question_id = NEW.id;

                IF NEW.kind = 'qcm_single' AND nb_options <> 4 THEN
                    RAISE EXCEPTION
                        'Publication refusée : un QCM à réponse unique compte exactement 4 options (% trouvée(s)).',
                        nb_options;
                END IF;

                IF nb_correctes <> 1 THEN
                    RAISE EXCEPTION
                        'Publication refusée : exactement une bonne réponse est attendue (% trouvée(s)).',
                        nb_correctes;
                END IF;

                IF nb_sans_justif > 0 THEN
                    RAISE EXCEPTION
                        'Publication refusée : % option(s) sans justification.', nb_sans_justif;
                END IF;

                IF NEW.eligible_for_diagnostic AND nb_sans_cause > 0 THEN
                    RAISE EXCEPTION
                        'Publication refusée : % distracteur(s) sans cause d''erreur (fiche F03).', nb_sans_cause;
                END IF;

                IF NEW.eligible_for_diagnostic OR NEW.eligible_for_simulation THEN
                    SELECT count(*) INTO nb_sources_ok
                    FROM question_sources
                    WHERE question_id = NEW.id AND verification = 'verified';

                    IF nb_sources_ok = 0 THEN
                        RAISE EXCEPTION
                            'Publication refusée : une question de diagnostic ou de simulation exige une source de contenu vérifiée.';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    /**
     * CONTRE-REVUE BLOC-2 — l'invariant rôle/permission cassable à deux
     * transactions.
     *
     *     T1 attribue le rôle global « candidat » dans un organisme, sans valider.
     *     T2 attache « tenants.manage » à ce même rôle, sans valider.
     *     T1 ne voit pas la permission, T2 ne voit pas l'appartenance.
     *     Les deux triggers acceptent. L'état combiné est une escalade.
     *
     * Les deux triggers verrouillent désormais la MÊME ligne `roles` avant de
     * contrôler. C'est le point de rendez-vous qui manquait : la seconde
     * transaction attend, puis relit un état où l'écriture de la première est
     * visible, et refuse.
     */
    private function roleSerialise(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_membership_role_scope()
            RETURNS TRIGGER AS $$
            DECLARE
                role_tenant   bigint;
                role_code     text;
                role_is_staff boolean;
                reservees     int;
            BEGIN
                -- Point de rendez-vous : la ligne du rôle, verrouillée.
                SELECT tenant_id, code, is_staff
                INTO role_tenant, role_code, role_is_staff
                FROM roles WHERE id = NEW.role_id FOR UPDATE;

                IF role_tenant IS NOT NULL THEN
                    IF role_tenant <> NEW.tenant_id THEN
                        RAISE EXCEPTION
                            'Le rôle « % » appartient à un autre organisme : il ne peut pas être attribué ici.',
                            role_code;
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.tenant_id = 1 THEN
                    RETURN NEW;
                END IF;

                IF role_is_staff THEN
                    RAISE EXCEPTION
                        'Le rôle de plateforme « % » est un rôle de back-office : il ne peut pas être attribué dans un organisme.',
                        role_code;
                END IF;

                SELECT count(*) INTO reservees
                FROM permission_role pr
                JOIN permissions p ON p.id = pr.permission_id
                WHERE pr.role_id = NEW.role_id AND p.platform_only;

                IF reservees > 0 THEN
                    RAISE EXCEPTION
                        'Le rôle de plateforme « % » porte % permission(s) réservée(s) : il ne peut pas être attribué dans un organisme.',
                        role_code, reservees;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

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

                -- Même point de rendez-vous que le trigger d'appartenance.
                SELECT tenant_id, code INTO role_tenant, code_role
                FROM roles WHERE id = NEW.role_id FOR UPDATE;

                IF role_tenant IS NOT NULL THEN
                    RAISE EXCEPTION
                        'La permission « % » est réservée à la plateforme : elle ne peut pas être attachée au rôle d''organisme « % ».',
                        code_permission, code_role;
                END IF;

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
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS question_sources_frozen ON question_sources');
        DB::unprepared('DROP TRIGGER IF EXISTS question_options_frozen ON question_options');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_question_child_frozen()');
        DB::unprepared('DROP FUNCTION IF EXISTS statut_question_verrouille(bigint)');
    }
};
