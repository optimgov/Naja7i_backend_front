<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quand le candidat a TRAVAILLÉ, et non quand il a ouvert.
 *
 * `started_at` date l'ouverture, et rien d'autre. Trier là-dessus classe une
 * tentative travaillée ce matin DERRIÈRE une tentative ouverte hier et
 * abandonnée aussitôt — exactement l'inverse de ce qu'un écran de reprise doit
 * montrer. Et sans cette date, aucune interface ne peut écrire « reprendre —
 * il y a 2 h », qui est la seule phrase utile de cet écran.
 *
 * POURQUOI UNE COLONNE, ET NON UNE DÉRIVATION. La dernière activité se déduit
 * du maximum de `responses.answered_at` sur les items de la tentative. Trois
 * raisons de ne pas s'en contenter :
 *
 *  - le tri porterait une sous-requête corrélée par ligne, sur le chemin le
 *    plus fréquent de l'écran d'accueil ;
 *  - la route unitaire devrait refaire le même calcul, ou l'oublier ;
 *  - « dernière activité » est un fait du domaine, pas un artefact de requête.
 *    Une tentative soumise sans avoir été rouverte a une dernière activité, et
 *    c'est sa soumission.
 *
 * `updated_at` ne convient pas : répondre écrit dans `responses`, pas dans
 * `attempts`. La colonne ne bougeait donc pas quand le candidat travaillait —
 * c'est le piège que cette migration ferme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attempts', function (Blueprint $table) {
            $table->timestampTz('last_activity_at')->nullable()->after('submitted_at');
        });

        /* Reconstruction pour les tentatives existantes : la plus tardive des
         * trois traces d'activité qu'elles portent déjà. Faute de quoi tout
         * l'historique se retrouverait en tête ou en queue d'un seul bloc. */
        DB::statement(
            'UPDATE attempts a SET last_activity_at = GREATEST(
                 a.started_at,
                 COALESCE(a.submitted_at, a.started_at),
                 COALESCE((
                     SELECT max(r.answered_at) FROM responses r
                       JOIN attempt_items ai ON ai.id = r.attempt_item_id
                      WHERE ai.attempt_id = a.id
                 ), a.started_at)
             )'
        );

        DB::statement('ALTER TABLE attempts ALTER COLUMN last_activity_at SET NOT NULL');

        /* L'index sert le tri de `GET me/attempts`, qui lit toujours par
         * candidat puis par activité décroissante. */
        DB::statement(
            'CREATE INDEX attempts_user_last_activity
             ON attempts (tenant_id, user_id, last_activity_at DESC)'
        );

        DB::statement(
            'COMMENT ON COLUMN attempts.last_activity_at IS
             $$Derniere trace de travail : ouverture, reponse, ou soumission. Distincte de started_at, qui ne date que l ouverture.$$'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attempts_user_last_activity');

        Schema::table('attempts', function (Blueprint $table) {
            $table->dropColumn('last_activity_at');
        });
    }
};
