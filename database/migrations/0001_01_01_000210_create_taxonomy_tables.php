<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAS-4 — Taxonomie de compétences (ADR-0012).
 *
 * Le point central : la profondeur n'est PAS fixée par le schéma. Une seule
 * table de nœuds, chacun pointant vers son parent. Quatre niveaux pour le
 * CRMEF, trois pour un concours dont le cadre de référence en compte trois :
 * c'est la même table, sans migration.
 *
 * Le profil, renseigné à la création du concours, déclare combien de niveaux
 * existent, comment ils s'appellent dans les deux langues, et à quelle
 * profondeur minimale une question doit être rattachée pour être publiable.
 */
return new class extends Migration
{
    /** ADR-0012 : borne assumée. Aucun cadre réel n'exige davantage. */
    private const MAX_DEPTH = 6;

    public function up(): void
    {
        Schema::create('taxonomy_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('exam_family_id')->unique()->constrained()->cascadeOnDelete();

            /*
             * Les niveaux, ordonnés du plus général au plus fin :
             * [{"depth":0,"name_fr":"Pilier","name_ar":"ركيزة"}, …]
             * Le vocabulaire du cadre de référence est repris tel quel — c'est
             * tout l'objet de l'ADR-0012.
             */
            $table->jsonb('levels');

            /* Profondeur minimale de rattachement d'une question publiable.
             * Pour le CRMEF, la microcompétence (3) : le prompt experts
             * l'impose déjà. Un concours au cadre moins fin aura un seuil
             * plus haut — d'où un paramètre par famille, pas une règle globale. */
            $table->unsignedSmallInteger('min_depth_for_publication')->default(0);

            $table->text('source_note_fr')->nullable();   // d'où vient ce cadre
            $table->text('source_note_ar')->nullable();
            $table->timestampsTz();
        });

        DB::statement(
            'ALTER TABLE taxonomy_profiles ADD CONSTRAINT taxonomy_profiles_levels_bounded
             CHECK (jsonb_array_length(levels) BETWEEN 1 AND '.self::MAX_DEPTH.')'
        );

        Schema::create('competency_nodes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('exam_family_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('competency_nodes')->restrictOnDelete();

            $table->string('code');                        // CG1, CG1.1 — celui du cadre d'origine
            $table->string('name_fr');
            $table->string('name_ar');
            $table->text('description_fr')->nullable();
            $table->text('description_ar')->nullable();

            $table->unsignedSmallInteger('depth')->default(0);

            /* Chemin matérialisé « 1.7.23 » : permet de récupérer tout un
             * sous-arbre en une requête, sans récursion. C'est ce qui rend
             * l'agrégation de maîtrise praticable à toute profondeur. */
            $table->string('path', 255)->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();

            $table->unique(['exam_family_id', 'code']);
            $table->index(['exam_family_id', 'parent_id', 'position']);
            $table->index(['exam_family_id', 'depth']);
            $table->index('path');
        });

        DB::statement(
            'ALTER TABLE competency_nodes ADD CONSTRAINT competency_nodes_depth_bounded
             CHECK (depth BETWEEN 0 AND '.(self::MAX_DEPTH - 1).')'
        );

        // Un nœud ne peut pas être son propre parent. Le cas plus large — un
        // nœud devenu son propre ancêtre — est vérifié applicativement, avec
        // un test dédié.
        DB::statement(
            'ALTER TABLE competency_nodes ADD CONSTRAINT competency_nodes_not_self_parent
             CHECK (parent_id IS NULL OR parent_id <> id)'
        );

        // Un nœud enfant appartient forcément à la même famille que son parent.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION competency_node_same_family()
            RETURNS TRIGGER AS $$
            DECLARE parent_family bigint;
            BEGIN
                IF NEW.parent_id IS NULL THEN RETURN NEW; END IF;

                SELECT exam_family_id INTO parent_family
                FROM competency_nodes WHERE id = NEW.parent_id;

                IF parent_family <> NEW.exam_family_id THEN
                    RAISE EXCEPTION
                        'Un nœud de compétence ne peut pas avoir un parent d''une autre famille de concours.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER competency_nodes_same_family
                BEFORE INSERT OR UPDATE ON competency_nodes
                FOR EACH ROW EXECUTE FUNCTION competency_node_same_family();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS competency_nodes_same_family ON competency_nodes');
        DB::unprepared('DROP FUNCTION IF EXISTS competency_node_same_family()');
        Schema::dropIfExists('competency_nodes');
        Schema::dropIfExists('taxonomy_profiles');
    }
};
