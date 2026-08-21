<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lot 3A.2 — Une portée est un couple typé, jamais un UUID nu (ADR-0031).
 *
 * Tous les droits livrés avant ce pas ont été émis sans portée. Ils sont
 * explicitement normalisés vers la racine avant que la contrainte ferme les
 * états mi-nuls et les types non prévus par le contrat commercial.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TYPE grant_scope_type AS ENUM (
                'audience',
                'filiere',
                'exam_family',
                'exam',
                'competency_node'
            )
            SQL);

        DB::statement('ALTER TABLE access_grants ADD COLUMN scope_type grant_scope_type NULL');

        DB::statement('UPDATE access_grants SET scope_type = NULL, scope_uuid = NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE access_grants
            ADD CONSTRAINT access_grants_scope_pair_complete
            CHECK (
                (scope_type IS NULL AND scope_uuid IS NULL)
                OR (scope_type IS NOT NULL AND scope_uuid IS NOT NULL)
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX access_grants_scope_resolution
            ON access_grants (user_id, capability, scope_type, scope_uuid)
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS access_grants_scope_resolution');
        DB::statement('ALTER TABLE access_grants DROP CONSTRAINT IF EXISTS access_grants_scope_pair_complete');
        DB::statement('ALTER TABLE access_grants DROP COLUMN IF EXISTS scope_type');
        DB::statement('DROP TYPE IF EXISTS grant_scope_type');
    }
};
