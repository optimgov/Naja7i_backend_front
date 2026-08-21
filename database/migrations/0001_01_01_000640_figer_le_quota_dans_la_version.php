<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P-Q — Le quota se fige dans la version d'offre, par instantané.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE DÉFAUT QUE CE PAS FERME
 *
 * Un `QuotaProfile` est AMENDABLE : sa valeur et ses bornes se déplacent
 * depuis le registre pédagogique, avec justification et journal (lot 3A.5).
 * Une version d'offre qui relirait `quota_profiles.value` à l'honoration
 * livrerait donc, à une commande passée hier, la valeur d'aujourd'hui. C'est
 * exactement le défaut V-3 — « la commande lisait le plan courant au lieu de
 * sa version » — sous un autre nom, et l'ADR-0026 l'a déjà tranché : la
 * version porte TOUT ce dont `honorer()` a besoin.
 *
 * À la composition d'une version, les valeurs du profil sélectionné sont donc
 * COPIÉES : code, unité, fenêtre, valeur, les deux bornes du moment et les
 * deux justifications qui les fondent. Amender le profil ensuite ne touche
 * aucune version existante ; le profil amendé ne sert qu'aux compositions
 * futures. L'instantané est couvert par l'immuabilité du trigger 000610.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * DES COLONNES SUR LA VERSION, PAS UNE TABLE FILLE
 *
 * La spécification d'administration commerciale (§5) déclare UN champ
 * « profil de quota » par version, et `QuotaUnit` ne compte qu'une unité —
 * chaque unité désignant exactement une capacité, un second quota sur une même
 * version exigerait d'abord une seconde unité, donc une migration de toute
 * façon. Une table fille aujourd'hui coûterait son propre déclencheur
 * d'immuabilité, en face de celui de `plan_versions` : deux gardes pour un
 * seul invariant, c'est une de trop. Le contrat reste ce qu'il est déjà ici —
 * une ligne, lisible d'un seul SELECT.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI LES JUSTIFICATIONS SONT COPIÉES, ET PAS POINTÉES
 *
 * « Référence de justification » ne peut pas être un pointeur vers le profil :
 * le profil est précisément ce qui bouge. Ce serait promettre une preuve
 * opposable et rendre l'état courant — le défaut qu'on ferme. Le journal
 * `quota_profile_events` ne peut pas non plus servir de référence : le profil
 * semé n'en porte aucun (une migration n'a pas d'auteur humain). La seule
 * référence qui reste vraie dans dix mois est le TEXTE au moment de la
 * sélection.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * L'ENVELOPPE SUR LE DROIT — ALLOCATION SEULE
 *
 * « Aucun second circuit : le profil se matérialise en enveloppe sur le droit,
 * par la chaîne offre → version → commande → droit » (spécification §4). Ce
 * pas livre le dernier maillon : l'honoration inscrit sur l'octroi l'unité, la
 * valeur et la fenêtre LUES SUR LA VERSION. Rien de plus : ni reliquat, ni
 * décrément, ni trace de service — la consommation est le lot 3B, et un
 * compteur posé ici sans son verrouillage serait un reliquat que personne ne
 * sait débiter correctement.
 *
 * Un octroi ne se modifie jamais (000280) : l'enveloppe allouée est donc, elle
 * aussi, figée à sa création. Un renouvellement crée une nouvelle enveloppe,
 * comme l'ADR-0027 le demande.
 */
return new class extends Migration
{
    /** Les colonnes de l'instantané, dans l'ordre où elles se lisent. */
    private const INSTANTANE = [
        'quota_profile_id',
        'quota_profile_code',
        'quota_unit',
        'quota_periodicity',
        'quota_value',
        'quota_min_value',
        'quota_max_value',
        'quota_min_justification',
        'quota_max_justification',
    ];

    public function up(): void
    {
        $this->selectionnerSurLOffre();
        $this->figerSurLaVersion();
        $this->allouerSurLOctroi();
    }

    /**
     * La SÉLECTION vit sur `plans` : c'est un geste d'administration
     * commerciale, révocable tant qu'aucune version ne l'a figée. Nulle =
     * l'offre ne pose aucune enveloppe, et l'illimité reste une ABSENCE de
     * profil, jamais un nombre (ADR-0027).
     */
    private function selectionnerSurLOffre(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->foreignId('quota_profile_id')->nullable()->after('capabilities')
                ->constrained('quota_profiles')->restrictOnDelete();
        });
    }

    private function figerSurLaVersion(): void
    {
        Schema::table('plan_versions', function (Blueprint $table) {
            $table->foreignId('quota_profile_id')->nullable()->after('capabilities')
                ->constrained('quota_profiles')->restrictOnDelete();
            $table->string('quota_profile_code', 64)->nullable()->after('quota_profile_id');
            $table->unsignedInteger('quota_value')->nullable()->after('quota_profile_code');
            $table->unsignedInteger('quota_min_value')->nullable()->after('quota_value');
            $table->unsignedInteger('quota_max_value')->nullable()->after('quota_min_value');
            $table->text('quota_min_justification')->nullable()->after('quota_max_value');
            $table->text('quota_max_justification')->nullable()->after('quota_min_justification');
        });

        DB::statement('ALTER TABLE plan_versions ADD COLUMN quota_unit quota_unit NULL');
        DB::statement('ALTER TABLE plan_versions ADD COLUMN quota_periodicity quota_periodicity NULL');

        /* TOUT OU RIEN. Un instantané à moitié copié est pire qu'absent : il
         * se lit comme un contrat alors qu'il manque la borne qui le fonde. */
        $colonnes = implode(', ', self::INSTANTANE);
        $total = count(self::INSTANTANE);

        DB::statement(<<<SQL
            ALTER TABLE plan_versions
            ADD CONSTRAINT plan_versions_quota_snapshot_complete
            CHECK (num_nonnulls({$colonnes}) IN (0, {$total}))
            SQL);

        /* Les mêmes invariants que le registre, recopiés avec la valeur :
         * l'instantané doit rester vérifiable SANS relire le profil. */
        DB::statement(<<<'SQL'
            ALTER TABLE plan_versions
            ADD CONSTRAINT plan_versions_quota_within_bounds
            CHECK (
                quota_value IS NULL
                OR (
                    quota_min_value > 0
                    AND quota_min_value <= quota_value
                    AND quota_value <= quota_max_value
                )
            )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE plan_versions
            ADD CONSTRAINT plan_versions_quota_bounds_justified
            CHECK (
                quota_min_justification IS NULL
                OR (
                    length(btrim(quota_min_justification)) >= 20
                    AND length(btrim(quota_max_justification)) >= 20
                )
            )
            SQL);
    }

    private function allouerSurLOctroi(): void
    {
        Schema::table('access_grants', function (Blueprint $table) {
            $table->unsignedInteger('quota_value')->nullable()->after('ends_at');
        });

        DB::statement('ALTER TABLE access_grants ADD COLUMN quota_unit quota_unit NULL');
        DB::statement('ALTER TABLE access_grants ADD COLUMN quota_periodicity quota_periodicity NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE access_grants
            ADD CONSTRAINT access_grants_quota_envelope_complete
            CHECK (num_nonnulls(quota_unit, quota_periodicity, quota_value) IN (0, 3))
            SQL);

        /* Une enveloppe de zéro n'est pas une enveloppe : c'est une capacité
         * vendue qui ne sert rien. Elle se refuse ici, pas à l'écran. */
        DB::statement(<<<'SQL'
            ALTER TABLE access_grants
            ADD CONSTRAINT access_grants_quota_value_positive
            CHECK (quota_value IS NULL OR quota_value > 0)
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE access_grants DROP CONSTRAINT IF EXISTS access_grants_quota_value_positive');
        DB::statement('ALTER TABLE access_grants DROP CONSTRAINT IF EXISTS access_grants_quota_envelope_complete');
        DB::statement('ALTER TABLE access_grants DROP COLUMN IF EXISTS quota_periodicity');
        DB::statement('ALTER TABLE access_grants DROP COLUMN IF EXISTS quota_unit');

        Schema::table('access_grants', function (Blueprint $table) {
            $table->dropColumn('quota_value');
        });

        DB::statement('ALTER TABLE plan_versions DROP CONSTRAINT IF EXISTS plan_versions_quota_bounds_justified');
        DB::statement('ALTER TABLE plan_versions DROP CONSTRAINT IF EXISTS plan_versions_quota_within_bounds');
        DB::statement('ALTER TABLE plan_versions DROP CONSTRAINT IF EXISTS plan_versions_quota_snapshot_complete');
        DB::statement('ALTER TABLE plan_versions DROP COLUMN IF EXISTS quota_periodicity');
        DB::statement('ALTER TABLE plan_versions DROP COLUMN IF EXISTS quota_unit');

        Schema::table('plan_versions', function (Blueprint $table) {
            $table->dropForeign(['quota_profile_id']);
            $table->dropColumn([
                'quota_profile_id', 'quota_profile_code', 'quota_value',
                'quota_min_value', 'quota_max_value',
                'quota_min_justification', 'quota_max_justification',
            ]);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropForeign(['quota_profile_id']);
            $table->dropColumn('quota_profile_id');
        });
    }
};
