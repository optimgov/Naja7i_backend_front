<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DET-54 — les deux citations officielles n'existaient qu'en français.
 *
 * `official_scoring_note_fr` et `official_admission_threshold_note_fr` n'avaient
 * AUCUNE colonne `_ar`, quand `coverage_note` en avait une depuis les
 * fondations. L'asymétrie n'avait jamais eu de surface pour se voir ; le
 * rapport d'examen blanc (PAS-35) l'a exposée, et la capture arabe de l'écran
 * E11 l'a rendue visible : un candidat arabophone lisait « Barème détaillé non
 * précisé par le descriptif officiel » en français, sur une page en arabe.
 *
 * NULLABLES, comme leurs jumelles françaises. Ces champs restent vides tant
 * qu'une source officielle ne les établit pas — c'est la règle des blueprints
 * et elle ne change pas ici. Ajouter la colonne n'oblige pas à la remplir ; ce
 * qu'elle permet, c'est de ne plus être contraint au français quand la
 * traduction existe.
 *
 * `dir="auto"` côté frontend rendait déjà le mélange LISIBLE, ce qui masquait
 * le problème sans le régler. Une lisibilité obtenue par contournement n'est
 * pas une traduction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blueprints', function (Blueprint $table) {
            $table->text('official_scoring_note_ar')->nullable()->after('official_scoring_note_fr');
            $table->text('official_admission_threshold_note_ar')
                ->nullable()
                ->after('official_admission_threshold_note_fr');
        });
    }

    public function down(): void
    {
        Schema::table('blueprints', function (Blueprint $table) {
            $table->dropColumn(['official_scoring_note_ar', 'official_admission_threshold_note_ar']);
        });
    }
};
