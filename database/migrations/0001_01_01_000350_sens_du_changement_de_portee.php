<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PAS-14.2 — Correctif de la contre-revue PAS-14.1.
 *
 * INVERSION DE SENS dans la garde du PAS-14.
 *
 * Le cas croisé contrôlait les permissions réservées dans la direction
 * `organisme → global`. Or c'est l'autre sens qui crée l'état interdit :
 *
 *     Un rôle GLOBAL peut légitimement porter `tenants.manage` — c'est le cas
 *     de `super_admin`, et la garde du pivot l'autorise explicitement.
 *     Le rendre LOCAL transporte cette permission dans l'organisme.
 *
 * Et rien ne rattrapait ensuite : le rôle n'ayant aucune appartenance, la
 * garde de portée laissait passer ; puis `assert_membership_role_scope()`
 * retourne immédiatement pour un rôle local de son propre organisme, sans
 * inspecter ses permissions.
 *
 * La base contenait donc un rôle d'organisme portant une permission réservée —
 * exactement l'état que les gardes du pivot prétendent rendre impossible.
 *
 * Le commentaire du PAS-14 justifiait le mauvais sens en toute confiance. Une
 * garde symétrique dans son intention doit l'être dans son code : quand un
 * attribut a deux directions de mutation, il faut se demander laquelle crée
 * l'état interdit, et non supposer que c'est celle qu'on a en tête.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_role_attributes_stable()
            RETURNS TRIGGER AS $$
            DECLARE
                appartenances   int;
                hors_plateforme int;
                reservees       int;
            BEGIN
                IF NEW.tenant_id IS NOT DISTINCT FROM OLD.tenant_id
                   AND NEW.is_staff IS NOT DISTINCT FROM OLD.is_staff THEN
                    RETURN NEW;
                END IF;

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

                /*
                 * 3. LE SENS QUI COMPTE — corrigé au PAS-14.2.
                 *
                 * Devenir un rôle d'organisme alors qu'on porte une permission
                 * réservée à la plateforme. C'est le seul chemin qui produise
                 * l'état interdit, et le PAS-14 gardait l'autre.
                 */
                IF NEW.tenant_id IS NOT NULL AND OLD.tenant_id IS NULL THEN
                    SELECT count(*) INTO reservees
                    FROM permission_role pr
                    JOIN permissions p ON p.id = pr.permission_id
                    WHERE pr.role_id = NEW.id AND p.platform_only;

                    IF reservees > 0 THEN
                        RAISE EXCEPTION
                            'Le rôle « % » porte % permission(s) réservée(s) à la plateforme : il ne peut pas devenir un rôle d''organisme.',
                            NEW.code, reservees;
                    END IF;
                END IF;

                /*
                 * 4. Le sens inverse reste contrôlé — non parce qu'il escalade
                 * en lui-même, mais parce qu'un rôle global portant des
                 * permissions réservées devient attribuable partout ; la garde
                 * d'appartenance le rattraperait, autant refuser en amont.
                 */
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
        SQL);
    }

    public function down(): void
    {
        // La version antérieure est restaurée par la migration 000340.
    }
};
