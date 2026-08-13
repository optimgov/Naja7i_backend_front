<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Une seule simulation ouverte à la fois par candidat.
 *
 * Même mécanique que `attempts_single_open_diagnostic` et
 * `attempts_single_open_training` : un index unique partiel plutôt qu'un
 * contrôle applicatif, pour qu'un double clic ou deux onglets ne puissent pas
 * ouvrir deux examens blancs.
 *
 * PORTÉE GLOBALE, ET NON PAR ÉPREUVE — contrairement au diagnostic.
 *
 * On peut diagnostiquer deux concours en parallèle : un diagnostic dure dix
 * minutes et ne prétend rien reproduire. Un examen blanc porte une ÉCHÉANCE
 * DURE de plusieurs heures, prise sur `exams.duration_minutes`. Deux
 * simulations ouvertes signifieraient que l'une court dans le vide pendant que
 * le candidat compose l'autre : à son retour, il trouverait une épreuve
 * expirée qu'il n'a jamais passée, close par le serveur et comptée dans sa
 * maîtrise. C'est la portée de l'entraînement qu'il faut ici, pas celle du
 * diagnostic — pour une raison qui lui est propre, le chronomètre.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS attempts_single_open_simulation');
        DB::statement(
            "CREATE UNIQUE INDEX attempts_single_open_simulation
             ON attempts (tenant_id, user_id)
             WHERE kind = 'simulation' AND status = 'in_progress'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attempts_single_open_simulation');
    }
};
