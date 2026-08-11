<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F05 — une seule question miroir ouverte par candidat.
 *
 * `mirror` figure dans `attempt_kind` depuis le PAS-6 : rien à ajouter au type,
 * seulement l'invariant qui manquait.
 *
 * Même mécanique et même portée que l'entraînement et la révision — par
 * CANDIDAT, tous concours confondus, et non par épreuve comme le diagnostic.
 * Un miroir est un geste de vérification immédiate : deux ouverts en parallèle
 * signifieraient que le candidat en a abandonné un, et le second effacerait la
 * trace du premier au lieu de la reprendre.
 *
 * L'index est ce qui GARANTIT l'invariant ; le service, lui, ne fait que
 * traduire sa violation en reprise (BLOC-4 de l'audit PAS-21). Le contrôle
 * applicatif seul laisserait passer deux requêtes simultanées.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS attempts_single_open_mirror
             ON attempts (user_id)
             WHERE kind = 'mirror' AND status = 'in_progress'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attempts_single_open_mirror');
    }
};
