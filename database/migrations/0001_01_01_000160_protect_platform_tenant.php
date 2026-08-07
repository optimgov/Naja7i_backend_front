<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PAS-1.1 — Deux corrections structurelles sur le tenant plateforme.
 *
 * 1. UUIDv7 (P3). La migration du PAS-1 utilisait `gen_random_uuid()`, qui
 *    produit un UUIDv4 — en contradiction directe avec la règle R6 du projet.
 *    PostgreSQL 16 n'a pas de générateur v7 natif (arrivé en PG 18) : on
 *    génère donc la valeur côté PHP.
 *
 * 2. Protection en base (audit §4.2). L'index partiel garantissait « au plus
 *    un tenant plateforme », pas « au moins un », ni son immutabilité. La
 *    ligne id=1 pouvait être supprimée ou transformée, et toute requête HTTP
 *    échouait ensuite sur firstOrFail(). Une garde applicative ne suffit pas :
 *    une migration ou un accès SQL direct la contournerait. D'où un trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Remplacer l'UUIDv4 du tenant plateforme par un UUIDv7.
        DB::table('tenants')->where('id', 1)->update([
            'uuid' => (string) Str::uuid7(),
        ]);

        // 2. Interdire suppression et mutation des attributs structurants.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_platform_tenant()
            RETURNS TRIGGER AS $$
            BEGIN
                IF (TG_OP = 'DELETE') THEN
                    IF OLD.id = 1 OR OLD.kind = 'platform' THEN
                        RAISE EXCEPTION
                            'Le tenant plateforme est structurel et ne peut pas être supprimé.';
                    END IF;
                    RETURN OLD;
                END IF;

                IF (TG_OP = 'UPDATE') THEN
                    IF OLD.id = 1 AND (
                           NEW.id   IS DISTINCT FROM OLD.id
                        OR NEW.kind IS DISTINCT FROM OLD.kind
                        OR NEW.slug IS DISTINCT FROM OLD.slug
                    ) THEN
                        RAISE EXCEPTION
                            'Les attributs structurants du tenant plateforme (id, kind, slug) sont immuables.';
                    END IF;

                    IF OLD.id <> 1 AND NEW.kind = 'platform' THEN
                        RAISE EXCEPTION
                            'Un tenant existant ne peut pas être promu tenant plateforme.';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER tenants_protect_platform
                BEFORE UPDATE OR DELETE ON tenants
                FOR EACH ROW EXECUTE FUNCTION protect_platform_tenant();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS tenants_protect_platform ON tenants');
        DB::unprepared('DROP FUNCTION IF EXISTS protect_platform_tenant()');
    }
};
