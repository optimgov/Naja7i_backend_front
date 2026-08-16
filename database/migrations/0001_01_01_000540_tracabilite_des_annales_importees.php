<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ce qu'une annale importée doit porter pour être relue — lot CRMEF-2, phase 3.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * `import_ref` — L'EMPREINTE QUI REND L'IMPORT REJOUABLE
 *
 * Le patron des cinq bloquants : une clé stable, et un index unique PARTIEL en
 * dernier recours. Rejouer l'import ne doit pas créer de doublon, et ce n'est
 * pas au code applicatif d'en répondre seul — deux exécutions concurrentes se
 * croiseraient entre le SELECT et l'INSERT.
 *
 * La clé est `(sujet, numéro de question)` : c'est l'identité que le corpus
 * lui-même emploie, et celle de la table de classement. Elle est canonisée en
 * `Q<entier sans zéro initial>` avant d'entrer ici — le corpus titre tantôt
 * `Q1`, tantôt `Q 1`, tantôt `Q01`, et la note de vérification du classement
 * avertit que la jointure ÉCHOUERAIT EN SILENCE sur deux blocs entiers si les
 * deux côtés ne canonisaient pas de la même façon.
 *
 * PARTIEL, parce que la banque rédigée à la main n'a pas de référence d'import
 * et n'en aura jamais : un index total refuserait la deuxième question écrite
 * par un auteur.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * `import_note` — LES MARQUES MANUSCRITES, ET RIEN D'AUTRE QU'UNE TRACE
 *
 * Les scans portent des croix, des cercles, des coches. Ce ne sont PAS des
 * réponses : le corpus établit qu'elles sont anonymes, non officielles, et
 * « contradictoires ou multiples sur des dizaines de questions » — Q85 de 2024
 * en porte deux (D et E), Q110 de 2025 en porte trois.
 *
 * Elles sont donc importées ICI, en texte, et JAMAIS dans `is_correct`. La
 * distinction n'est pas cosmétique : `is_correct` est ce que le produit
 * enseigne, `import_note` est ce que le relecteur doit examiner. Confondre les
 * deux publierait une bonne réponse tirée d'un gribouillage.
 *
 * La colonne porte aussi la fiabilité de lecture — « claire », « partiellement
 * illisible » — et la page d'origine. Un relecteur doit voir tout de suite ce
 * qui est douteux, sans rouvrir le corpus.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* Gardes de rejeu : la première exécution a posé les colonnes puis
         * échoué sur l'index — `questions` n'a pas de `tenant_id`, le catalogue
         * étant global (DET-05, matrice « catalogue global / activité isolée »).
         * La migration doit pouvoir repasser sans redemander ce qui existe. */
        Schema::table('questions', function (Blueprint $table) {
            if (! Schema::hasColumn('questions', 'import_ref')) {
                $table->string('import_ref')->nullable();
            }

            if (! Schema::hasColumn('questions', 'import_note')) {
                $table->text('import_note')->nullable();
            }
        });

        DB::statement(
            'COMMENT ON COLUMN questions.import_ref IS
             $$Empreinte stable (sujet, numero canonise) d une annale importee.
             NUL pour toute question redigee a la main.$$'
        );

        DB::statement(
            'COMMENT ON COLUMN questions.import_note IS
             $$Tracabilite de lecture : marques manuscrites relevees sur le scan, fiabilite, page.
             Ce ne sont JAMAIS des reponses — voir la partie 4.1 du corpus.$$'
        );

        /* SUR `import_ref` SEULE, sans organisme : la banque de questions est
         * un CATALOGUE GLOBAL, propriété de la plateforme — `questions` ne
         * porte pas de `tenant_id`, contrairement aux tentatives et aux
         * réponses. La première écriture de cette migration l'avait supposé et
         * PostgreSQL l'a refusée ; la matrice du DET-05 disait déjà l'inverse. */
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS questions_import_ref_unique
             ON questions (import_ref)
             WHERE import_ref IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS questions_import_ref_unique');

        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn(['import_ref', 'import_note']);
        });
    }
};
