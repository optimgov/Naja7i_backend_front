<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F07, seconde moitié — la révision devient une tentative comme les autres.
 *
 * `review` rejoint les quatre genres de tentative. Un genre à part et non un
 * entraînement déguisé : la sélection ne vient ni des poids officiels ni d'un
 * domaine faible, mais du calendrier — ce qui est ÉCHU aujourd'hui. Les trois
 * autres genres se distinguent déjà pour des raisons de même nature, et
 * `AttemptResource` sert `kind` au client, qui doit pouvoir nommer l'écran.
 *
 * HORS TRANSACTION, et ce n'est pas une facilité. PostgreSQL refuse
 * d'UTILISER une valeur d'énumération ajoutée dans la même transaction que son
 * ajout — or l'index partiel ci-dessous a besoin du littéral `'review'` dans
 * son prédicat. Laravel enveloppe les migrations dans une transaction sur
 * PostgreSQL ; `$withinTransaction = false` la lève pour que l'ajout du genre
 * soit commis avant que l'index ne s'y réfère. Le prix est connu : les deux
 * instructions ne sont plus atomiques entre elles. Elles sont toutes deux
 * idempotentes en pratique — `IF NOT EXISTS` sur l'une comme sur l'autre.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("ALTER TYPE attempt_kind ADD VALUE IF NOT EXISTS 'review'");

        /* UNE SEULE SESSION DE RÉVISION OUVERTE PAR CANDIDAT, tous concours
         * confondus. Même portée que l'entraînement, et pour la même raison :
         * deux sessions ouvertes en parallèle serviraient deux fois les mêmes
         * rendez-vous échus, et la première soumise ferait avancer des paliers
         * que la seconde croirait encore en retard.
         *
         * Portée volontairement différente du diagnostic, unique par ÉPREUVE :
         * réviser est un geste quotidien du candidat, pas un acte par concours.
         */
        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS attempts_single_open_review
             ON attempts (user_id)
             WHERE kind = 'review' AND status = 'in_progress'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attempts_single_open_review');

        /* La valeur d'énumération ne se retire pas : PostgreSQL n'a pas de
         * `ALTER TYPE ... DROP VALUE`. Reconstruire le type supposerait de
         * réécrire la colonne de toutes les tentatives existantes — une
         * migration destructive pour annuler un ajout. On laisse la valeur. */
    }
};
