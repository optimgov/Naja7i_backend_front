<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Le diagnostic gratuit v1.1 reçoit son propre profil de dix questions.
 *
 * Cette migration ajoute une donnée de référence, mais ne bascule aucune
 * offre : sur une base peuplée, ce geste doit être prévisualisé puis exécuté
 * par `naja7i:activer-diagnostic-gratuit-v1-1`. C'est ce passage par le modèle
 * Plan qui compose une PlanVersion neuve sans réécrire les anciennes.
 */
return new class extends Migration
{
    private const CODE = 'decouverte-v11-10';

    public function up(): void
    {
        if (DB::table('quota_profiles')->where('code', self::CODE)->exists()) {
            return;
        }

        $maintenant = now();

        DB::table('quota_profiles')->insert([
            'uuid' => (string) Str::uuid7(),
            'code' => self::CODE,
            'name_fr' => 'Diagnostic gratuit — 10 questions',
            'name_ar' => 'التشخيص المجاني — 10 أسئلة',
            'unit' => 'questions',
            'periodicity' => 'cumulative_grant',
            'value' => 10,
            'min_value' => 10,
            'max_value' => 120,
            'min_justification' => 'Dix questions constituent le diagnostic gratuit décidé pour la v1.1 : '
                .'le réduire empêcherait de couvrir son parcours minimal.',
            'max_justification' => 'La borne historique de cent vingt questions reste la limite pédagogique '
                .'supérieure du registre, sans élargir le diagnostic gratuit.',
            'active' => true,
            'position' => 5,
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ]);
    }

    public function down(): void
    {
        /* Un profil ne se supprime jamais et peut déjà être figé dans une
         * version composée après l'allumage. Le rollback le conserve : tenter
         * de l'effacer violerait le registre append-only et réécrirait le
         * contrat de cette version. */
    }
};
