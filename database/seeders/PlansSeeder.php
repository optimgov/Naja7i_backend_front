<?php

namespace Database\Seeders;

use App\Contracts\AccessGrant;
use App\Models\Audience;
use App\Models\Plan;
use App\Models\QuotaProfile;
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
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA COMPOSITION VIENT DE L'ARBITRAGE D-CAT, PAS DE CE FICHIER
 *
 * Ce semis n'a rien décidé : il EXÉCUTE les quatre décisions du 22 août 2026,
 * par le même canal que l'écran des offres — le modèle `Plan`, dont chaque
 * enregistrement contractuel crée une version. Semer ne réécrit donc aucune
 * commande passée ; il compose la version suivante.
 *
 *   · D-CAT-1 — `questions.answer` sur les TROIS offres payantes, SANS profil
 *     de quota. « L'illimité est une absence de profil, jamais un nombre »
 *     (ADR-0027). Sans elle, depuis l'ADR-0033, un candidat qui paie clôt son
 *     essai et perd le droit de répondre : il paierait pour perdre.
 *   · D-CAT-2 — `annales.practice` FERMÉE partout, PAR CHOIX : Q-21 exige
 *     l'audit du marqueur d'annales avant ouverture. L'ouvrir plus tard ne
 *     coûtera qu'une version d'offre. Le choix est écrit pour n'être pas subi.
 *   · D-CAT-3 — `session-180j` compose la profondeur (`mastery.detail`,
 *     `remediation.plan`, `memory.sessions`) et devient l'offre de référence
 *     du droit transitoire. Jamais `certification.take` : la fonction n'existe
 *     pas, et vendable ≠ existant (P6).
 *   · D-CAT-4 — les paliers cessent d'être nommés par un prix : Entrée,
 *     Préparation, Session complète. « Palier 200 » se lit Préparation,
 *     « palier 600 » se lit Session complète.
 *
 * LES NOMS ARABES SONT POSÉS, PAS ARRÊTÉS. La relecture bilingue appartient à
 * O-6 ; une clé vide aurait rendu le catalogue muet en arabe, ce qui est pire
 * qu'une formulation à revoir.
 */
class PlansSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * LE SOCLE PAYANT — ce que les trois offres ouvrent, sans exception.
         *
         * `questions.answer` en tête, et SANS profil de quota sur ces trois
         * offres : l'absence de profil EST l'illimité (ADR-0027). Un candidat
         * qui paie ne perd jamais le droit de répondre.
         */
        $socle = [
            AccessGrant::QUESTIONS_ANSWER,
            AccessGrant::CAUSE_REVEAL,
            AccessGrant::SERIES_TARGETED,
            AccessGrant::SIMULATOR_FULL,
        ];

        /*
         * LA PROFONDEUR — ce que la session complète vend en plus.
         *
         * Ce ne sont pas des mesures mais des PRESCRIPTIONS : la carte par
         * matière et chapitre, l'ordonnance, la séance mémoire. A-02 nomme la
         * granularité comme le différenciateur, pas l'existence de la mesure.
         */
        $profondeur = [
            AccessGrant::MASTERY_DETAIL,
            AccessGrant::REMEDIATION_PLAN,
            AccessGrant::MEMORY_SESSIONS,
        ];

        /*
         * LE PORTEUR DU GRATUIT — ADR-0025.
         *
         * Une offre comme les autres, semée par le même chemin : c'est le point
         * de l'ADR. Prix RÉELLEMENT zéro — pas une remise, pas un paiement nul
         * déguisé — durée vide, donc droit SANS TERME, et une seule capacité :
         * répondre aux questions. Son enveloppe vient du profil pédagogique
         * « Découverte » du registre, dont la version figera l'instantané
         * (M-003). Elle ne paraît pas au catalogue : elle se reçoit.
         *
         * Les quatre nombres du quota ne sont pas ici et n'y seront jamais : ils
         * appartiennent au registre pédagogique, et ce semis SÉLECTIONNE un
         * profil comme le ferait l'admin commerciale à l'écran.
         */
        $gratuite = [
            'code' => 'decouverte-gratuite',
            'name_fr' => 'Découverte',
            'name_ar' => 'الاكتشاف',
            'description_fr' => 'Ce que chaque compte reçoit à l’inscription : de quoi voir '
                .'la méthode à l’œuvre sur ses propres réponses.',
            'description_ar' => 'ما يحصل عليه كل حساب عند التسجيل: ما يكفي لرؤية الطريقة '
                .'وهي تشتغل على أجوبته الخاصة.',
            'price_cents' => 0,
            'duration_days' => null,
            'capabilities' => [AccessGrant::QUESTIONS_ANSWER],
            'position' => 0,
        ];

        $plans = [
            [
                /* « Entrée » et non plus « Découverte — 7 jours » : le porteur du
                 * gratuit s'appelle déjà Découverte, et deux offres du même nom
                 * — l'une gratuite, l'autre à 49 MAD — se confondent au premier
                 * écran. La durée se lit dans sa colonne, pas dans le nom. */
                'code' => 'decouverte-7j',
                'name_fr' => 'Entrée',
                'name_ar' => 'المدخل',
                'description_fr' => 'Une semaine pour voir ce que la méthode change, sur toutes les fonctions.',
                'description_ar' => 'أسبوع لتكتشف ما تغيّره الطريقة، بكل الوظائف.',
                'price_cents' => 4900,
                'duration_days' => 7,
                'capabilities' => $socle,
                'position' => 1,
            ],
            [
                'code' => 'preparation-30j',
                'name_fr' => 'Préparation',
                'name_ar' => 'التهييء',
                'description_fr' => 'Le mois qui précède l’épreuve : causes, entraînement ciblé et examens blancs.',
                'description_ar' => 'الشهر السابق للاختبار: الأسباب، والتدريب الموجّه، والامتحانات التجريبية.',
                'price_cents' => 19900,
                'duration_days' => 30,
                'capabilities' => $socle,
                'position' => 2,
            ],
            [
                /* La session couvre la préparation ENTIÈRE d'un concours annuel.
                 * Le prix au jour y est le plus bas : c'est l'offre qu'on veut
                 * voir choisie, et le tarif le dit sans qu'un bandeau le crie.
                 *
                 * C'est aussi elle que le droit transitoire nomme : elle compose
                 * le plus, et le geste de pose exige désormais qu'on la nomme. */
                'code' => 'session-180j',
                'name_fr' => 'Session complète',
                'name_ar' => 'الدورة الكاملة',
                'description_fr' => 'Toute la préparation d’une session de concours, sans interruption.',
                'description_ar' => 'كامل التهييء لدورة مباراة، دون انقطاع.',
                'price_cents' => 69900,
                'duration_days' => 180,
                'capabilities' => [...$socle, ...$profondeur],
                'position' => 3,
            ],
        ];

        /* Le public éligible est contractuel (Q-19) : ces trois offres sont
         * celles du concours CRMEF, et le dire ici évite qu'un catalogue frais
         * porte des offres sans public — la migration ne rattache que ce qui
         * existait avant elle. */
        $public = Audience::where('code', 'crmef')->value('id');

        foreach ($plans as $plan) {
            Plan::updateOrCreate(
                ['code' => $plan['code']],
                $plan + ['currency' => 'MAD', 'active' => true, 'audience_id' => $public],
            );
        }

        Plan::updateOrCreate(
            ['code' => $gratuite['code']],
            $gratuite + [
                'currency' => 'MAD',
                'active' => true,
                'auto_granted' => true,
                'audience_id' => $public,
                'quota_profile_id' => QuotaProfile::where('code', 'decouverte')->value('id'),
            ],
        );
    }
}
