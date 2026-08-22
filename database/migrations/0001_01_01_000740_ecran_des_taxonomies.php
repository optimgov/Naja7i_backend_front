<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot TAXO — ce qu'un poids doit porter pour exister.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * AUCUNE VALEUR PÉDAGOGIQUE SANS SA RAISON
 *
 * C'est l'exigence déjà tenue par les bornes de quota (3A.5), appliquée là où
 * elle compte le plus : `weight_percent` gouverne la composition de CHAQUE
 * série servie au candidat, par la méthode des plus forts restes. Un nombre
 * qui décide de ce que les gens travaillent, et dont personne ne sait d'où il
 * vient, est exactement ce que ce dépôt refuse ailleurs.
 *
 * La contrainte est en base et non dans un formulaire, pour la raison
 * habituelle : un semis, une commande, un correctif à chaud passent par les
 * mêmes refus.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * `official` NE SE DÉCLARE PAS, IL S'ÉTABLIT
 *
 * Les migrations 000520/000530 ont rétrogradé en `reported` les poids qui se
 * disaient officiels sans pièce à l'appui. Rien n'empêchait de les y remonter
 * d'un `UPDATE`. Ce déclencheur ferme le chemin : `official` exige une source
 * ATTACHÉE et VÉRIFIÉE (`sources.verified_at`). Le jour où le descriptif
 * arrive et qu'un relecteur le vérifie, le même geste devient possible — et
 * c'est bien ainsi qu'on veut qu'il se passe.
 *
 * `reported` et `observed` restent libres : ils n'affirment rien qu'on ne
 * puisse tenir. Rapporté dit « une origine nommée, jamais lue » ; observé dit
 * « recompté sur notre corpus », et c'est notre propre travail.
 */
return new class extends Migration
{
    /** Sous vingt caractères, une justification n'en est pas une. */
    private const JUSTIFICATION_MINIMALE = 20;

    public function up(): void
    {
        Schema::table('competency_nodes', function (Blueprint $table) {
            $table->text('weight_justification')->nullable()->after('weight_percent');
        });

        /*
         * LES POIDS DÉJÀ EN BASE REÇOIVENT LA LEUR, ET ELLE EST VRAIE.
         *
         * On n'invente pas une raison pédagogique : on écrit le fait établi par
         * la migration 000520 — ces poids viennent d'un descriptif nommé, daté
         * et paginé, que personne dans ce dépôt n'a lu. C'est précisément ce
         * que `reported` signifie, et le dire en toutes lettres évite qu'un
         * lecteur croie à une valeur d'architecte.
         */
        DB::statement(<<<'SQL'
            UPDATE competency_nodes
            SET weight_justification =
                'Poids rapporté par le descriptif de l''épreuve, dont la pièce n''a pas été '
                || 'vérifiée dans ce dépôt (DET-60). Repris tel quel : rien ne le contredit, '
                || 'et l''inventer autrement serait pire.'
            WHERE weight_percent IS NOT NULL AND weight_justification IS NULL
            SQL);

        /*
         * `IS NOT NULL` EXPLICITE, ET CE N'EST PAS DE LA CEINTURE-BRETELLE.
         *
         * `length(btrim(NULL)) >= 20` vaut NULL, et PostgreSQL tient une
         * contrainte NULL pour SATISFAITE. Sans ce terme, la contrainte laissait
         * passer exactement le cas qu'elle existe pour refuser — un poids sans
         * aucune justification — et n'attrapait que les justifications trop
         * courtes. Une contrainte qui ne refuse rien est pire qu'aucune : elle
         * se lit comme une garantie.
         */
        DB::statement(sprintf(
            'ALTER TABLE competency_nodes
             ADD CONSTRAINT competency_nodes_weight_justified
             CHECK (weight_percent IS NULL
                    OR (weight_justification IS NOT NULL
                        AND length(btrim(weight_justification)) >= %d))',
            self::JUSTIFICATION_MINIMALE,
        ));

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_official_weight_has_verified_source()
            RETURNS TRIGGER AS $$
            BEGIN
                IF NEW.provenance <> 'official' THEN
                    RETURN NEW;
                END IF;

                IF NEW.source_id IS NULL THEN
                    RAISE EXCEPTION
                        'Un poids officiel exige une source attachee : le noeud « % » n''en a aucune.',
                        NEW.code;
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM sources
                    WHERE id = NEW.source_id AND verified_at IS NOT NULL
                ) THEN
                    RAISE EXCEPTION
                        'Un poids officiel exige une source VERIFIEE : celle du noeud « % » ne l''est pas.',
                        NEW.code;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER competency_nodes_official_needs_verified_source
                BEFORE INSERT OR UPDATE ON competency_nodes
                FOR EACH ROW EXECUTE FUNCTION assert_official_weight_has_verified_source();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(
            'DROP TRIGGER IF EXISTS competency_nodes_official_needs_verified_source ON competency_nodes'
        );
        DB::statement('DROP FUNCTION IF EXISTS assert_official_weight_has_verified_source()');
        DB::statement('ALTER TABLE competency_nodes DROP CONSTRAINT IF EXISTS competency_nodes_weight_justified');

        Schema::table('competency_nodes', function (Blueprint $table) {
            $table->dropColumn('weight_justification');
        });
    }
};
