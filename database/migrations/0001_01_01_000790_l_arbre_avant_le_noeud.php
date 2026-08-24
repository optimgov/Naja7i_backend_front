<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * « Classée par arbre, pas encore par nœud » — l'état qui n'existait pas.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI MANQUAIT
 *
 * Une question préparée pendait d'un `competency_node_id` nullable, et un nœud
 * pend d'une épreuve. Il n'y avait aucun niveau intermédiaire : nœud nul
 * voulait dire « on ne sait rien », alors que pour un corpus rangé par voie et
 * discipline on sait déjà BEAUCOUP — on sait de quelle épreuve la question
 * relève, on ignore seulement quel domaine elle travaille.
 *
 * Sans cette colonne, la file de qualification ne pouvait pas être « filtrée
 * par arbre » : elle n'avait pas de quoi filtrer.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * L'ÉPREUVE EST L'ARBRE — on ne crée pas un troisième vocabulaire
 *
 * Le corpus parle d'« arbres » (`SE`, `DID-SEC-AR`…), le dépôt parle
 * d'épreuves (`CRMEF-SE-2025`), et la taxonomie pend de l'épreuve depuis
 * `000230`. Ce sont les mêmes objets sous deux noms. La colonne pointe donc
 * `exams`, et la table de correspondance vit dans la commande d'import, là où
 * elle se lit — pas dans une table de plus.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE TRIGGER : DEUX CHAMPS QUI SE CONTREDISENT NE DOIVENT PAS COEXISTER
 *
 * Une ligne qualifiée sur un nœud de l'épreuve X mais rangée sous l'épreuve Y
 * est un mensonge silencieux — et c'est le pire genre, puisque les deux champs
 * ont l'air renseignés. La contrainte croise les deux tables, donc un CHECK ne
 * suffit pas : il faut un trigger.
 *
 * Il ne s'applique QUE si les deux sont renseignés. Une ligne sans épreuve et
 * sans nœud reste licite (rien n'est su), comme une ligne avec épreuve et sans
 * nœud (l'état que cette migration existe pour représenter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prepared_questions', function (Blueprint $table) {
            $table->foreignId('exam_id')->nullable()->after('batch_id')
                ->constrained()->restrictOnDelete();

            /* La file de qualification lit « les lignes actives de tel arbre,
               dans tel état ». C'est son seul accès de masse. */
            $table->index(['exam_id', 'state', 'active'], 'prepared_questions_arbre_file_index');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_prepared_question_node_matches_exam()
            RETURNS TRIGGER AS $$
            DECLARE
                epreuve_du_noeud bigint;
            BEGIN
                IF NEW.competency_node_id IS NULL OR NEW.exam_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT exam_id INTO epreuve_du_noeud
                  FROM competency_nodes WHERE id = NEW.competency_node_id;

                IF epreuve_du_noeud IS DISTINCT FROM NEW.exam_id THEN
                    RAISE EXCEPTION
                        'Le nœud de qualification n''appartient pas à l''épreuve de la ligne préparée.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER prepared_questions_node_matches_exam
                BEFORE INSERT OR UPDATE OF competency_node_id, exam_id ON prepared_questions
                FOR EACH ROW EXECUTE FUNCTION assert_prepared_question_node_matches_exam();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS prepared_questions_node_matches_exam ON prepared_questions');
        DB::unprepared('DROP FUNCTION IF EXISTS assert_prepared_question_node_matches_exam()');

        Schema::table('prepared_questions', function (Blueprint $table) {
            $table->dropIndex('prepared_questions_arbre_file_index');
            $table->dropConstrainedForeignId('exam_id');
        });
    }
};
