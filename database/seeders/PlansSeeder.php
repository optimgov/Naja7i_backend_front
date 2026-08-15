<?php

namespace Database\Seeders;

use App\Contracts\AccessGrant;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Trois offres réalistes — et le raisonnement de prix est écrit, pas deviné.
 *
 * LES MONTANTS SONT DES HYPOTHÈSES DE TRAVAIL, pas une décision commerciale
 * arrêtée : le pilote les tranchera. Ils sont posés ici pour que la surface
 * existe et se teste, et ils se changent en back-office sans déploiement —
 * c'est précisément pourquoi un plan est une ligne en base et non du code.
 *
 * Trois plans et pas dix : un candidat qui hésite entre trois offres choisit,
 * entre dix il s'en va.
 */
class PlansSeeder extends Seeder
{
    public function run(): void
    {
        $tout = [
            AccessGrant::CAUSE_REVEAL,
            AccessGrant::SERIES_TARGETED,
            AccessGrant::SIMULATOR_FULL,
        ];

        $plans = [
            [
                'code' => 'decouverte-7j',
                'name_fr' => 'Découverte — 7 jours',
                'name_ar' => 'اكتشاف — 7 أيام',
                'description_fr' => 'Une semaine pour voir ce que la méthode change, sur toutes les fonctions.',
                'description_ar' => 'أسبوع لتكتشف ما تغيّره الطريقة، بكل الوظائف.',
                'price_cents' => 4900,
                'duration_days' => 7,
                'capabilities' => $tout,
                'position' => 1,
            ],
            [
                'code' => 'preparation-30j',
                'name_fr' => 'Préparation — 30 jours',
                'name_ar' => 'تهييء — 30 يوما',
                'description_fr' => 'Le mois qui précède l’épreuve : causes, entraînement ciblé et examens blancs.',
                'description_ar' => 'الشهر السابق للاختبار: الأسباب، والتدريب الموجّه، والامتحانات التجريبية.',
                'price_cents' => 19900,
                'duration_days' => 30,
                'capabilities' => $tout,
                'position' => 2,
            ],
            [
                /* La session couvre la préparation ENTIÈRE d'un concours annuel.
                 * Le prix au jour y est le plus bas : c'est l'offre qu'on veut
                 * voir choisie, et le tarif le dit sans qu'un bandeau le crie. */
                'code' => 'session-180j',
                'name_fr' => 'Session complète — 6 mois',
                'name_ar' => 'الدورة الكاملة — 6 أشهر',
                'description_fr' => 'Toute la préparation d’une session de concours, sans interruption.',
                'description_ar' => 'كامل التهييء لدورة مباراة، دون انقطاع.',
                'price_cents' => 69900,
                'duration_days' => 180,
                'capabilities' => $tout,
                'position' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(
                ['code' => $plan['code']],
                $plan + ['currency' => 'MAD', 'active' => true],
            );
        }
    }
}
