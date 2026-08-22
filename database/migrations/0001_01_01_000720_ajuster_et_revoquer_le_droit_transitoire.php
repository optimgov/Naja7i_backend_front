<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 3A.8, pas 3 — Ajuster la fin, révoquer sans effacer.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * « CLOS, JAMAIS EFFACÉ »
 *
 * Q-17 : « La révocation n'efface pas la ligne : elle la clôt. » Le mécanisme
 * existe depuis le PAS-8 et n'a pas à être réinventé — « un octroi ne se
 * modifie jamais : une prolongation crée une nouvelle ligne, une révocation
 * pose `ends_at` ». On doit pouvoir répondre à « de quoi disposait ce candidat
 * le 14 mars », et une ligne supprimée ne répond plus.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA PÉRIODE VIDE DEVIENT LÉGALE, ET C'EST NÉCESSAIRE
 *
 * `ends_at > starts_at` interdisait de clore un droit QUI N'A PAS ENCORE
 * COMMENCÉ — le cas d'une pose datée que l'on annule avant sa prise d'effet.
 * Les contournements possibles étaient tous faux : reculer `starts_at`
 * réécrirait l'histoire, poser `ends_at` une seconde après laisserait le droit
 * s'ouvrir une seconde, et une colonne `revoked_at` créerait un second
 * mécanisme d'invalidité en face de celui que `active()` lit déjà.
 *
 * La contrainte devient donc `ends_at >= starts_at`. Une période VIDE est
 * exactement ce qu'est un droit clos avant d'avoir commencé : `active()` exige
 * `starts_at <= maintenant` ET `ends_at > maintenant`, qu'aucun instant ne
 * satisfait quand les deux bornes sont égales. Rien d'autre dans le produit ne
 * crée de période vide.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * UNE LIGNE DE JOURNAL PAR OCTROI TOUCHÉ
 *
 * Un geste porte sur le droit transitoire d'un compte, qui est fait d'autant
 * d'octrois que de capacités. Journaliser le geste seulement laisserait
 * invérifiable ce que chaque octroi valait avant : après deux ajustements
 * successifs, les échéances peuvent différer d'une capacité à l'autre. C'est
 * l'avant/après de CHAQUE ligne qui se relit, pas une moyenne.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE access_grants DROP CONSTRAINT IF EXISTS access_grants_period_coherent');
        DB::statement(
            'ALTER TABLE access_grants ADD CONSTRAINT access_grants_period_coherent
             CHECK (ends_at IS NULL OR ends_at >= starts_at)'
        );

        DB::statement("CREATE TYPE transition_change_kind AS ENUM ('adjusted', 'revoked')");

        Schema::create('transition_grant_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('access_grant_id')->constrained('access_grants')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('ends_at_before')->nullable();
            $table->timestampTz('ends_at_after');
            $table->text('reason');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['access_grant_id', 'occurred_at'], 'transition_changes_timeline_idx');
        });

        DB::statement('ALTER TABLE transition_grant_changes ALTER COLUMN uuid SET DEFAULT uuid7()');
        DB::statement('ALTER TABLE transition_grant_changes ADD COLUMN kind transition_change_kind NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE transition_grant_changes
            ADD CONSTRAINT transition_changes_reason_written
            CHECK (length(btrim(reason)) >= 10)
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_transition_change_mutation()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Le journal des ajustements de droit transitoire est en ajout seul.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER transition_grant_changes_append_only
                BEFORE UPDATE OR DELETE ON transition_grant_changes
                FOR EACH ROW EXECUTE FUNCTION refuse_transition_change_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS transition_grant_changes_append_only ON transition_grant_changes'
        );
        DB::statement('DROP FUNCTION IF EXISTS refuse_transition_change_mutation()');

        Schema::dropIfExists('transition_grant_changes');
        DB::statement('DROP TYPE IF EXISTS transition_change_kind');

        DB::statement('ALTER TABLE access_grants DROP CONSTRAINT IF EXISTS access_grants_period_coherent');
        DB::statement(
            'ALTER TABLE access_grants ADD CONSTRAINT access_grants_period_coherent
             CHECK (ends_at IS NULL OR ends_at > starts_at)'
        );
    }
};
