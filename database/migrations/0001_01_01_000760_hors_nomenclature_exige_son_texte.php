<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DET-16 — le neuvième code de cause, et le texte qui le rend utile.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI UNE MIGRATION SÉPARÉE
 *
 * `ALTER TYPE ... ADD VALUE` a eu lieu à la migration précédente, et
 * PostgreSQL refuse d'EMPLOYER une valeur d'énumération fraîchement ajoutée
 * dans la même transaction — or Laravel enveloppe chaque migration dans une
 * transaction sur PostgreSQL. C'est déjà ce qui a imposé le couple
 * 000520/000530 ; la note de cette migration-là affirme que « les migrations
 * de Laravel ne sont pas transactionnelles par défaut », ce qui est faux ici
 * et lui a fait prendre la bonne décision pour une raison inexacte.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE TEXTE N'EST PAS UN COMMENTAIRE, C'EST LA CONTREPARTIE
 *
 * Les huit codes de F03 ne sont pas validés pédagogiquement (DET-16). Un
 * expert qui ne trouve pas sa case en choisit une fausse — et c'est PIRE que
 * de n'en choisir aucune : la carte de maîtrise se met à mesurer un piège que
 * personne n'a tendu, silencieusement.
 *
 * Le neuvième code dit « aucun des huit ». Il n'a de valeur que s'il dit AUSSI
 * ce qui manquait : sans son texte, il devient la case fourre-tout où l'on
 * range ce qu'on n'a pas voulu qualifier, et la nomenclature ne s'améliore
 * jamais faute de savoir de quoi elle manque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_options', function (Blueprint $table) {
            $table->text('cause_note')->nullable()->after('cause');
        });

        /*
         * `IS NOT NULL` EXPLICITE. `length(btrim(NULL)) >= 10` vaut NULL, et
         * PostgreSQL tient une contrainte NULL pour SATISFAITE : sans ce terme,
         * la contrainte laisserait passer exactement le cas qu'elle existe pour
         * refuser.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE question_options
            ADD CONSTRAINT question_options_hors_nomenclature_justifiee
            CHECK (cause IS DISTINCT FROM 'hors_nomenclature'
                   OR (cause_note IS NOT NULL AND length(btrim(cause_note)) >= 10))
            SQL);
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE question_options
             DROP CONSTRAINT IF EXISTS question_options_hors_nomenclature_justifiee'
        );

        Schema::table('question_options', function (Blueprint $table) {
            $table->dropColumn('cause_note');
        });
    }
};
