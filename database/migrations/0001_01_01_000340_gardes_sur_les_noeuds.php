<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PAS-14 — Correctifs de la revue PAS-13.
 *
 * Même généralisation que l'ADR-0022, appliquée un cran plus haut.
 *
 * Les lots précédents ont gardé les ARÊTES du graphe d'autorisation :
 * l'écriture d'une `membership`, l'attachement d'une permission à un rôle.
 * Ils n'ont pas gardé les NŒUDS. Muter un attribut du rôle ou de la
 * permission après coup ne déclenchait aucune réévaluation :
 *
 *     Le rôle global « candidat » est attribué dans dix organismes.
 *     On le passe ensuite en `is_staff`, ou on lui donne un `tenant_id`.
 *     Aucun trigger de pivot ne se déclenche : les appartenances existantes
 *     ne repassent jamais dans la garde.
 *
 *     La permission « catalogue.view » est attachée à un rôle distribué.
 *     On la passe ensuite en `platform_only`. Même silence.
 *
 * Règle : quand un invariant relie deux tables par une table pivot, il faut
 * trois gardes — sur le pivot, et sur chacune des deux tables référencées.
 * Garder le pivot seul, c'est verrouiller la porte en laissant les murs
 * mobiles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->gardeAttributsDeRole();
        $this->gardeReservationDePermission();
    }

    /**
     * REVUE PAS-13 BLOC-1 — mutation des attributs d'un rôle distribué.
     *
     * Deux attributs de `roles` déterminent ce que le rôle a le droit d'être
     * attribué : `tenant_id` (à quel organisme il appartient) et `is_staff`
     * (s'il donne accès au back-office). Les changer après distribution
     * contourne la garde d'appartenance.
     *
     * Décisions :
     *  - `tenant_id` est IMMUABLE dès qu'une appartenance existe. La portée
     *    d'un rôle est structurelle : la déplacer reviendrait à transférer
     *    silencieusement des utilisateurs d'un organisme à un autre.
     *  - `is_staff` ne peut pas devenir vrai sur un rôle possédant une
     *    appartenance hors plateforme.
     *
     * Le verrou reprend le même point de rendez-vous que les triggers de
     * pivot : la ligne `roles` est déjà verrouillée par l'UPDATE en cours,
     * et les appartenances sont lues sous verrou.
     */
    private function gardeAttributsDeRole(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_role_attributes_stable()
            RETURNS TRIGGER AS $$
            DECLARE
                appartenances     int;
                hors_plateforme   int;
                reservees         int;
            BEGIN
                -- Rien de structurant ne change : on ne verrouille rien.
                IF NEW.tenant_id IS NOT DISTINCT FROM OLD.tenant_id
                   AND NEW.is_staff IS NOT DISTINCT FROM OLD.is_staff THEN
                    RETURN NEW;
                END IF;

                /* La ligne `roles` est déjà verrouillée par l'UPDATE en cours.
                 * On verrouille les appartenances pour que le contrôle voie
                 * une attribution concurrente non encore validée. */
                PERFORM 1 FROM memberships WHERE role_id = NEW.id FOR UPDATE;

                SELECT count(*),
                       count(*) FILTER (WHERE tenant_id <> 1)
                INTO appartenances, hors_plateforme
                FROM memberships WHERE role_id = NEW.id;

                -- 1. La portée d'un rôle distribué est figée.
                IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id AND appartenances > 0 THEN
                    RAISE EXCEPTION
                        'Le rôle « % » est attribué à % appartenance(s) : sa portée ne peut plus changer. Créez un autre rôle.',
                        NEW.code, appartenances;
                END IF;

                -- 2. Un rôle distribué hors plateforme ne devient pas back-office.
                IF NEW.is_staff AND NOT OLD.is_staff
                   AND NEW.tenant_id IS NULL AND hors_plateforme > 0 THEN
                    RAISE EXCEPTION
                        'Le rôle « % » est attribué à % appartenance(s) hors plateforme : il ne peut pas devenir un rôle de back-office.',
                        NEW.code, hors_plateforme;
                END IF;

                /* 3. Cas croisé : rendre global un rôle d'organisme ferait
                 *    hériter ses appartenances des permissions réservées qu'il
                 *    pourrait ensuite recevoir. Interdit dès qu'il porte des
                 *    permissions réservées. */
                IF NEW.tenant_id IS NULL AND OLD.tenant_id IS NOT NULL THEN
                    SELECT count(*) INTO reservees
                    FROM permission_role pr
                    JOIN permissions p ON p.id = pr.permission_id
                    WHERE pr.role_id = NEW.id AND p.platform_only;

                    IF reservees > 0 THEN
                        RAISE EXCEPTION
                            'Le rôle « % » porte % permission(s) réservée(s) : il ne peut pas devenir un rôle de plateforme.',
                            NEW.code, reservees;
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER roles_attributes_guard
                BEFORE UPDATE ON roles
                FOR EACH ROW EXECUTE FUNCTION assert_role_attributes_stable();
        SQL);
    }

    /**
     * REVUE PAS-13 BLOC-2 — une permission devient réservée après coup.
     *
     * `permissions.platform_only` détermine si la permission peut être portée
     * par un rôle distribué. La passer à vrai APRÈS l'attachement contournait
     * la garde du pivot : aucune ligne de `permission_role` n'est écrite, donc
     * aucun trigger ne se déclenche.
     *
     * Le contrôle porte sur l'état résultant : une permission ne devient
     * réservée que si aucun rôle la portant n'est distribué hors plateforme,
     * et si aucun rôle d'organisme ne la porte.
     */
    private function gardeReservationDePermission(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_permission_reservation_stable()
            RETURNS TRIGGER AS $$
            DECLARE
                roles_organisme  int;
                roles_distribues int;
            BEGIN
                -- Seul le passage à « réservée » est risqué. La lever ne peut
                -- que restreindre l'accès, jamais l'élargir.
                IF NOT (NEW.platform_only AND NOT OLD.platform_only) THEN
                    RETURN NEW;
                END IF;

                /* Verrou sur les rôles concernés, dans l'ordre des
                 * identifiants — même ordre que les triggers de pivot, pour
                 * qu'aucun interblocage ne remplace la sérialisation. */
                PERFORM 1 FROM roles r
                JOIN permission_role pr ON pr.role_id = r.id
                WHERE pr.permission_id = NEW.id
                ORDER BY r.id
                FOR UPDATE OF r;

                SELECT count(*) FILTER (WHERE r.tenant_id IS NOT NULL)
                INTO roles_organisme
                FROM roles r
                JOIN permission_role pr ON pr.role_id = r.id
                WHERE pr.permission_id = NEW.id;

                IF roles_organisme > 0 THEN
                    RAISE EXCEPTION
                        'La permission « % » est portée par % rôle(s) d''organisme : elle ne peut pas devenir réservée à la plateforme.',
                        NEW.code, roles_organisme;
                END IF;

                SELECT count(DISTINCT m.id) INTO roles_distribues
                FROM memberships m
                JOIN permission_role pr ON pr.role_id = m.role_id
                WHERE pr.permission_id = NEW.id AND m.tenant_id <> 1;

                IF roles_distribues > 0 THEN
                    RAISE EXCEPTION
                        'La permission « % » est accordée à % appartenance(s) hors plateforme : elle ne peut pas devenir réservée.',
                        NEW.code, roles_distribues;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER permissions_reservation_guard
                BEFORE UPDATE ON permissions
                FOR EACH ROW EXECUTE FUNCTION assert_permission_reservation_stable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS permissions_reservation_guard ON permissions');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_permission_reservation_stable()');
        DB::unprepared('DROP TRIGGER IF EXISTS roles_attributes_guard ON roles');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_role_attributes_stable()');
    }
};
