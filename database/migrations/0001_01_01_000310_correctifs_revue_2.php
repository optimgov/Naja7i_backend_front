<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PAS-11 — Correctifs de la revue PAS-9 / PAS-10.
 *
 * Le lot PAS-10 prétendait fermer le défaut du PAS-5 en retirant des champs de
 * `$fillable`. C'était une erreur de raisonnement : `$fillable` protège
 * l'assignation de masse depuis un tableau, pas une mise à jour Eloquent
 * explicite. `Question::whereKey($id)->update(['status' => 'published'])` la
 * traverse sans effort.
 *
 * Et le trigger de gel ne se déclenchait que si l'ANCIEN statut était déjà
 * `published` — donc jamais au moment de la publication elle-même.
 *
 * Leçon : une frontière de sécurité ne peut pas reposer sur une convention
 * d'assignation. Ce lot déplace tous les invariants dans des triggers qui
 * s'exécutent quel que soit le chemin d'écriture.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->gardeAppartenance();
        $this->gardePublication();
        $this->gelComplet();
    }

    /**
     * REVUE PAS-9 BLOC-1 — escalade de privilèges inter-tenant.
     *
     * `memberships.role_id` n'était contraint que par une clé étrangère simple.
     * Rien n'empêchait une appartenance dans l'organisme A de référencer un
     * rôle de l'organisme B, ni — beaucoup plus grave — le rôle global
     * `super_admin`, qui porte `tenants.manage`, `refunds.issue` et
     * `permissions.manage`.
     *
     * Le trigger du PAS-9 gardait l'attachement permission → rôle. Il ne
     * gardait pas l'attachement rôle → appartenance, qui est l'autre moitié du
     * chemin.
     *
     * Règle retenue :
     *  - rôle d'organisme : il doit appartenir au MÊME organisme ;
     *  - rôle de plateforme : attribuable dans un organisme uniquement s'il
     *    n'est pas `is_staff` et ne porte aucune permission réservée. Le rôle
     *    `candidat` reste donc attribuable partout, `super_admin` nulle part
     *    ailleurs que sur la plateforme.
     */
    private function gardeAppartenance(): void
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
                SELECT tenant_id, code, is_staff
                INTO role_tenant, role_code, role_is_staff
                FROM roles WHERE id = NEW.role_id;

                -- Rôle propre à un organisme : appartenance dans le même.
                IF role_tenant IS NOT NULL THEN
                    IF role_tenant <> NEW.tenant_id THEN
                        RAISE EXCEPTION
                            'Le rôle « % » appartient à un autre organisme : il ne peut pas être attribué ici.',
                            role_code;
                    END IF;

                    RETURN NEW;
                END IF;

                -- Rôle de plateforme, attribué sur la plateforme : toujours permis.
                IF NEW.tenant_id = 1 THEN
                    RETURN NEW;
                END IF;

                -- Rôle de plateforme, attribué dans un organisme : seulement
                -- s'il est inoffensif hors de la plateforme.
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

            CREATE TRIGGER memberships_role_scope_guard
                BEFORE INSERT OR UPDATE ON memberships
                FOR EACH ROW EXECUTE FUNCTION assert_membership_role_scope();
        SQL);
    }

    /**
     * REVUE PAS-10 BLOC-1 — le chemin de publication restait contournable.
     *
     * Ce trigger oppose les invariants éditoriaux AU MOMENT du passage à
     * `published`, quel que soit le chemin : service, mise à jour Eloquent,
     * import de masse, SQL brut, commande console.
     *
     * Il duplique délibérément la logique de `QuestionIntegrityChecker`. Cette
     * duplication est assumée : le service produit des messages lisibles pour
     * l'éditeur, la base garantit qu'aucun chemin ne l'esquive. Un test vérifie
     * qu'ils restent d'accord.
     */
    private function gardePublication(): void
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
            BEGIN
                IF NEW.status <> 'published' THEN
                    RETURN NEW;
                END IF;

                statut_precedent := CASE WHEN TG_OP = 'UPDATE' THEN OLD.status ELSE NULL END;

                -- Déjà publiée : ce n'est pas une transition, le gel s'en charge.
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

            CREATE TRIGGER questions_publication_guard
                BEFORE INSERT OR UPDATE ON questions
                FOR EACH ROW EXECUTE FUNCTION assert_question_publishable();
        SQL);
    }

    /**
     * REVUE PAS-10 BLOC-2 — le gel ne couvrait que six colonnes.
     *
     * `difficulty`, `remediation_id`, `mirror_question_id`, `author_id`,
     * `version` et le reste restaient modifiables sur une question publiée.
     * L'affirmation « contenu publié gelé » était donc trop large.
     *
     * Le nouveau trigger compare la ligne ENTIÈRE, moins une liste blanche
     * explicite. Ajouter une colonne à la table la gèle automatiquement — c'est
     * l'inverse du réflexe habituel, et c'est voulu : l'oubli doit produire
     * une protection, pas un trou.
     */
    private function gelComplet(): void
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

                -- Liste blanche : seuls ces champs bougent après publication.
                -- Tout le reste est gelé, y compris les colonnes futures.
                avant := to_jsonb(OLD) - 'status' - 'retired_at' - 'updated_at'
                                       - 'eligible_for_diagnostic' - 'eligible_for_simulation';
                apres := to_jsonb(NEW) - 'status' - 'retired_at' - 'updated_at'
                                       - 'eligible_for_diagnostic' - 'eligible_for_simulation';

                IF avant IS DISTINCT FROM apres THEN
                    RAISE EXCEPTION
                        'Une question publiée est gelée. Seuls le statut, le retrait et l''éligibilité changent. Créez une nouvelle version (ADR-0015 §5).';
                END IF;

                -- L'éligibilité ne peut que se restreindre après publication :
                -- l'élargir contournerait les contrôles de publication.
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
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS questions_publication_guard ON questions');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_question_publishable()');
        DB::unprepared('DROP TRIGGER IF EXISTS memberships_role_scope_guard ON memberships');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_membership_role_scope()');
    }
};
