<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une question servie et non répondue laisse enfin une trace.
 *
 * Jusqu'ici, sauter une question ne coûtait rien et rapportait : le calcul de
 * maîtrise part de `responses`, un item sans réponse n'y a pas de ligne, il
 * n'existe donc pas. Deux candidats sur la même série de dix, cinq bonnes
 * réponses chacun, se voyaient classés à l'envers de leur mérite —
 * celui qui répond faux cinq fois tombe à 50 et remonte dans l'ordonnance,
 * celui qui saute les cinq mêmes questions affiche 100 et en sort.
 *
 * `answered_count` ne pouvait pas porter cette information : il compte
 * l'évidence, et une question sautée n'en produit aucune. Il faut une
 * colonne distincte, car les deux nombres répondent à deux questions
 * différentes — « sur quoi ce score est-il fondé ? » et « qu'est-ce que le
 * candidat n'a pas affronté ? ».
 *
 * Aucune contrainte ne lie `skipped_count` à `answered_count` : leur somme
 * n'est PAS le nombre d'items servis sur la durée. Un item resservi dans une
 * seconde tentative est compté deux fois dans l'un ou dans l'autre, et un
 * item sauté puis répondu plus tard compte dans les deux. C'est voulu — ce
 * sont des compteurs d'événements, pas un inventaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mastery_scores', function (Blueprint $table) {
            /* Items servis au candidat sur une tentative CLOSE et laissés sans
             * réponse. Les tentatives en cours sont hors décompte : ne pas
             * avoir encore répondu n'est pas avoir sauté. */
            $table->unsignedSmallInteger('skipped_count')->default(0)->after('correct_count');
        });

        DB::statement(
            'COMMENT ON COLUMN mastery_scores.skipped_count IS
             $$Questions servies sur une tentative close et non répondues. Ne fonde aucun score : entre dans le classement de l ordonnance, jamais dans la maîtrise.$$'
        );
    }

    public function down(): void
    {
        Schema::table('mastery_scores', function (Blueprint $table) {
            $table->dropColumn('skipped_count');
        });
    }
};
