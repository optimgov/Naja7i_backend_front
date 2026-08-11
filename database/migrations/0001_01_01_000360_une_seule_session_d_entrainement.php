<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Une seule session d'entraînement ouverte à la fois par candidat.
 *
 * Même intention et même mécanique que `attempts_single_open_diagnostic` : un
 * index unique partiel plutôt qu'un contrôle applicatif, pour qu'un double clic
 * ou deux onglets ne puissent pas ouvrir deux sessions.
 *
 * Une différence de portée, voulue : le diagnostic est unique PAR ÉPREUVE — on
 * peut diagnostiquer deux concours en parallèle. L'entraînement est unique
 * TOUT COURT. Le candidat révise une chose à la fois ; deux sessions ouvertes
 * signifieraient qu'il en abandonne une, et l'ordonnance ne saurait plus
 * laquelle compte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS attempts_single_open_training');
        DB::statement(
            "CREATE UNIQUE INDEX attempts_single_open_training
             ON attempts (tenant_id, user_id)
             WHERE kind = 'training' AND status = 'in_progress'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attempts_single_open_training');
    }
};
