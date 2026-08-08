<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAS-4.1 — La taxonomie se rattache à l'ÉPREUVE, plus à la famille.
 *
 * Correction de l'ADR-0012, actée par l'ADR-0014. Les descriptifs officiels
 * CRMEF 2025 le montrent sans ambiguïté : chaque épreuve a sa propre matrice
 * de domaines et de poids.
 *
 *   Sciences de l'éducation  → 3 domaines, 6 sous-domaines
 *   Didactique du français   → 2 blocs,    9 sous-domaines
 *   Spécialité français      → 2 domaines, 10 sous-domaines
 *
 * Rattacher la taxonomie à la famille aurait forcé à fusionner ces trois
 * matrices en une seule — ce qui aurait rendu impossible tout simulateur
 * fidèle, puisque chaque épreuve se passe séparément.
 *
 * Les POIDS suivent une règle vérifiable : les enfants d'un nœud somment au
 * poids du parent, et les racines somment à 100. Un test l'impose.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Le profil suit l'épreuve --------------------------------------
        Schema::table('taxonomy_profiles', function (Blueprint $table) {
            $table->dropUnique(['exam_family_id']);
            $table->foreignId('exam_id')->nullable()->after('exam_family_id')
                ->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('exam_family_id')->nullable()->change();
        });

        // --- Les nœuds suivent l'épreuve, et portent leur poids -------------
        Schema::table('competency_nodes', function (Blueprint $table) {
            $table->foreignId('exam_id')->nullable()->after('exam_family_id')
                ->constrained()->restrictOnDelete();

            /*
             * Symétrique de ce qui est fait ci-dessus sur `taxonomy_profiles` :
             * le rattachement à la famille devient facultatif. Les nœuds du
             * référentiel officiel ne portent plus qu'une épreuve ; laisser
             * `exam_family_id` NOT NULL rendait la matrice CRMEF inchargeable.
             */
            $table->foreignId('exam_family_id')->nullable()->change();

            /* Poids officiel en pourcentage de l'épreuve. Nul lorsque le
             * descriptif n'en donne pas — jamais interpolé. */
            $table->decimal('weight_percent', 5, 2)->nullable()->after('depth');

            $table->foreignId('source_id')->nullable()->after('weight_percent')
                ->constrained()->nullOnDelete();

            $table->index(['exam_id', 'depth']);
        });

        DB::statement("ALTER TABLE competency_nodes ADD COLUMN provenance data_provenance NOT NULL DEFAULT 'unverified'");
        DB::statement(
            'ALTER TABLE competency_nodes ADD CONSTRAINT competency_nodes_weight_range
             CHECK (weight_percent IS NULL OR (weight_percent > 0 AND weight_percent <= 100))'
        );

        // L'unicité du code se calcule désormais par épreuve : FR-DID-CHAMP et
        // SE-PSY-DEV appartiennent à deux matrices distinctes.
        //
        // `$table->unique([...])` du PAS-4 a produit une CONTRAINTE UNIQUE, pas
        // un simple index. Sous PostgreSQL la contrainte possède son index :
        // `DROP INDEX` échoue alors avec « cannot drop index ... because
        // constraint ... requires it ». Il faut retirer la contrainte, ce qui
        // emporte l'index. Le `DROP INDEX` est conservé derrière, sans effet
        // ici, pour les bases où l'objet aurait été créé comme index nu.
        DB::statement('ALTER TABLE competency_nodes DROP CONSTRAINT IF EXISTS competency_nodes_exam_family_id_code_unique');
        DB::statement('DROP INDEX IF EXISTS competency_nodes_exam_family_id_code_unique');
        DB::statement(
            'CREATE UNIQUE INDEX competency_nodes_exam_code_unique
             ON competency_nodes (exam_id, code) WHERE exam_id IS NOT NULL'
        );

        // Le trigger « même famille » devient « même épreuve ».
        DB::unprepared('DROP TRIGGER IF EXISTS competency_nodes_same_family ON competency_nodes');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION competency_node_same_exam()
            RETURNS TRIGGER AS $$
            DECLARE
                parent_exam   bigint;
                parent_family bigint;
            BEGIN
                IF NEW.parent_id IS NULL THEN RETURN NEW; END IF;

                SELECT exam_id, exam_family_id INTO parent_exam, parent_family
                FROM competency_nodes WHERE id = NEW.parent_id;

                /* Les DEUX rattachements sont gardés, et c'est nécessaire :
                 * comparer la seule épreuve laisserait passer un parent d'une
                 * autre famille entre deux nœuds sans épreuve, puisque
                 * NULL IS DISTINCT FROM NULL est faux. La garde du PAS-4
                 * disparaîtrait alors sans bruit sur tout le catalogue
                 * antérieur au référentiel CRMEF. */
                IF parent_exam IS DISTINCT FROM NEW.exam_id
                   OR parent_family IS DISTINCT FROM NEW.exam_family_id THEN
                    RAISE EXCEPTION
                        'Un nœud de compétence doit partager l''épreuve et la famille de son parent.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER competency_nodes_same_exam
                BEFORE INSERT OR UPDATE ON competency_nodes
                FOR EACH ROW EXECUTE FUNCTION competency_node_same_exam();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS competency_nodes_same_exam ON competency_nodes');
        DB::unprepared('DROP FUNCTION IF EXISTS competency_node_same_exam()');

        Schema::table('competency_nodes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_id');
            $table->dropConstrainedForeignId('exam_id');
            $table->dropColumn(['weight_percent', 'provenance']);
        });

        Schema::table('taxonomy_profiles', fn (Blueprint $t) => $t->dropConstrainedForeignId('exam_id'));
    }
};
