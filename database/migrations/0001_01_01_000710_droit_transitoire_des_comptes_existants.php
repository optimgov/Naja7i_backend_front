<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 3A.8, pas 1 — Le droit transitoire, et la trace du geste qui le pose.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE Q-17 A DÉCIDÉ
 *
 * « À l'allumage du mur payant, tout compte déjà inscrit reçoit un droit
 * transitoire équivalent au palier 600, d'une durée de 60 jours, nommé, tracé
 * et visible. L'admin commerciale peut l'ajuster ou le révoquer. »
 *
 * Chacun de ces cinq mots a sa contrepartie technique. NOMMÉ : une origine
 * `transition`, distincte de `purchase`, pour qu'aucun agrégat commercial ne le
 * compte comme une vente. TRACÉ : cette table — auteur, date, motif, périmètre,
 * nombres. VISIBLE : l'écran d'abonnement, au pas 2. AJUSTABLE et RÉVOCABLE :
 * le pas 3.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI UNE TABLE DE GESTE, ET PAS SEULEMENT DES OCTROIS
 *
 * Les octrois disent ce que chaque compte a reçu. Ils ne disent pas QUI a
 * décidé de le distribuer, sur quel périmètre, avec quel motif, ni combien de
 * comptes étaient concernés au moment du geste. « Posé par une migration
 * silencieuse » est précisément ce que Q-17 refuse : un lot de distribution est
 * une DÉCISION, et une décision se relit.
 *
 * La table fige aussi les PARAMÈTRES retenus — offre de référence, version,
 * durée, public, date de pose. Le registre des paramètres pédagogiques n'existe
 * pas encore (lot 8) ; en attendant, la trace du geste est le seul endroit où
 * « pourquoi 60 et pas 90 » restera lisible dans six mois.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA VERSION D'OFFRE EST FIGÉE DANS LA TRACE
 *
 * Le droit transitoire est « équivalent au palier 600 » : il faut donc pouvoir
 * répondre, plus tard, à « équivalent à QUOI, exactement ». La composition du
 * palier peut changer le lendemain ; la version, elle, ne change jamais
 * (M-003). C'est elle qu'on retient, pas le plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE grant_origin ADD VALUE IF NOT EXISTS 'transition'");

        Schema::create('transition_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();

            /* L'offre de référence ET sa version : « équivalent au palier 600 »
             * n'a de sens que si l'on peut relire à quoi, exactement. */
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_version_id')->constrained('plan_versions')->restrictOnDelete();

            /* Nul = tous les comptes candidats. Sinon, ceux dont l'épreuve
             * déclarée relève de cette catégorie de public. */
            $table->foreignId('audience_id')->nullable()->constrained('audiences')->restrictOnDelete();

            $table->unsignedSmallInteger('duration_days');
            $table->timestampTz('starts_at');
            $table->text('reason');

            /* Les trois nombres du compte rendu, figés au moment du geste : ce
             * que la prévisualisation avait annoncé doit rester vérifiable
             * après coup, même si la population a bougé depuis. */
            $table->unsignedInteger('accounts_targeted');
            $table->unsignedInteger('accounts_granted');
            $table->unsignedInteger('accounts_skipped');

            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('occurred_at', 'transition_batches_timeline_idx');
        });

        DB::statement('ALTER TABLE transition_batches ALTER COLUMN uuid SET DEFAULT uuid7()');

        DB::statement(<<<'SQL'
            ALTER TABLE transition_batches
            ADD CONSTRAINT transition_batches_reason_written
            CHECK (length(btrim(reason)) >= 10)
            SQL);

        /* Les bornes de la durée sont ici parce qu'un service se contourne et
         * qu'une contrainte non : sous une semaine, un « sevrage annoncé » n'en
         * est pas un ; au-delà de six mois, ce n'est plus une transition mais un
         * palier gratuit déguisé, que personne n'a décidé de vendre. */
        DB::statement(<<<'SQL'
            ALTER TABLE transition_batches
            ADD CONSTRAINT transition_batches_duration_bounded
            CHECK (duration_days BETWEEN 7 AND 180)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE transition_batches
            ADD CONSTRAINT transition_batches_counts_coherent
            CHECK (accounts_granted + accounts_skipped = accounts_targeted)
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_transition_batch_mutation()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Le journal des poses de droit transitoire est en ajout seul.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER transition_batches_append_only
                BEFORE UPDATE OR DELETE ON transition_batches
                FOR EACH ROW EXECUTE FUNCTION refuse_transition_batch_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS transition_batches_append_only ON transition_batches');
        DB::statement('DROP FUNCTION IF EXISTS refuse_transition_batch_mutation()');

        Schema::dropIfExists('transition_batches');

        /* `grant_origin` conserve `transition` : PostgreSQL ne retire pas une
         * valeur d'énumération, et reconstruire le type détruirait les octrois
         * qui la portent. */
    }
};
