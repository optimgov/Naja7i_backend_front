<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lot 3A.6, pas 1 — La catégorie de public devient un objet (ferme DET-87).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE DET-87 CONSTATAIT
 *
 * « La portée `audience` est fermée en code mais le catalogue ne possède pas
 * encore d'objet `Audience` ni de rattachement parcourable depuis une famille.
 * Un droit d'audience se résout donc exactement et globalement, sans ascendance
 * inventée. » Le type existait, l'objet qu'il désigne n'existait pas.
 *
 * Deux choses manquaient, et les voici : la CATÉGORIE elle-même, objet de
 * catalogue que l'admin commerciale crée sans développeur, et son RATTACHEMENT
 * depuis une famille d'épreuves — sans quoi un droit `(audience, lycee)` ne
 * peut couvrir aucune épreuve, ce que le scénario S-11 exige explicitement.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * L'AUDIENCE EST AU-DESSUS DE LA FAMILLE, PAS DE LA FILIÈRE
 *
 * Une filière regroupe des concours par nature administrative (sciences de
 * l'éducation, post-bac, fonction publique) ; une catégorie de public regroupe
 * des candidats par situation (CRMEF, lycée, grandes écoles). Les deux se
 * croisent sans se contenir : le rattachement se pose donc là où le scénario
 * S-11 le pose — sur la FAMILLE, « une famille rattachée » à la catégorie.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * UN SEUL SEMIS, ET IL EST FACTUEL
 *
 * `crmef` seule. Les catégories suivantes se créent à l'écran — c'est tout
 * l'objet du lot. Le rattachement ne concerne que la famille `crmef` : les
 * autres familles du catalogue (licences d'éducation, agrégation, COPS,
 * post-bac) sont en liste d'attente et personne n'a décidé de leur public.
 * Leur inventer un rattachement ici serait une donnée fausse dans un chemin
 * d'autorisation — précisément ce que DET-87 refusait.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->creerLesCategories();
        $audienceId = $this->semerCrmef();
        $this->rattacherLaFamilleCrmef($audienceId);
        $this->rattacherLesOffresExistantes($audienceId);
    }

    private function creerLesCategories(): void
    {
        Schema::create('audiences', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            /* Stable et lisible : il désigne la catégorie dans une portée de
             * droit et dans une version d'offre déjà vendue. */
            $table->string('code', 32)->unique();

            $table->string('name_fr');
            $table->string('name_ar');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE audiences
            ADD CONSTRAINT audiences_code_format
            CHECK (code ~ '^[a-z][a-z0-9-]{2,31}$')
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE audiences
            ADD CONSTRAINT audiences_named_in_both_languages
            CHECK (btrim(name_fr) <> '' AND btrim(name_ar) <> '')
            SQL);

        /*
         * LES DEUX INTERDITS DU §3, TENUS EN BASE ET PAS À L'ÉCRAN.
         *
         * « Elle ne peut pas supprimer une version, une offre ni une
         * catégorie » : on retire de la sélection, on n'efface pas — une
         * version vendue peut désigner cette catégorie comme public éligible.
         * Et le code est figé après création pour la même raison : le changer
         * réécrirait la lecture d'une portée déjà octroyée.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuse_audience_deletion()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'Une categorie de public se retire de la selection, elle ne se supprime jamais.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audiences_never_deleted
                BEFORE DELETE ON audiences
                FOR EACH ROW EXECUTE FUNCTION refuse_audience_deletion();

            CREATE OR REPLACE FUNCTION refuse_audience_code_change()
            RETURNS TRIGGER AS $$
            BEGIN
                IF NEW.code IS DISTINCT FROM OLD.code THEN
                    RAISE EXCEPTION
                        'Le code d une categorie de public est fige : une version d offre et des droits le designent.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audiences_code_frozen
                BEFORE UPDATE ON audiences
                FOR EACH ROW EXECUTE FUNCTION refuse_audience_code_change();
        SQL);
    }

    /** Le libellé arabe n'est pas inventé : il est repris du catalogue (`CatalogueSeeder`). */
    private function semerCrmef(): int
    {
        $maintenant = now();

        return DB::table('audiences')->insertGetId([
            'uuid' => (string) Str::uuid7(),
            'code' => 'crmef',
            'name_fr' => 'CRMEF',
            'name_ar' => 'المراكز الجهوية لمهن التربية والتكوين',
            'active' => true,
            'position' => 10,
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ]);
    }

    private function rattacherLaFamilleCrmef(int $audienceId): void
    {
        Schema::table('exam_families', function (Blueprint $table) {
            $table->foreignId('audience_id')->nullable()->after('filiere_id')
                ->constrained('audiences')->restrictOnDelete();
        });

        DB::table('exam_families')->where('slug', 'crmef')->update(['audience_id' => $audienceId]);
    }

    /**
     * Le public éligible est CONTRACTUEL (Q-19) : il vit donc aussi sur la
     * version, et les versions existantes reçoivent la même valeur que leur
     * offre. Les laisser nulles ferait diverger la projection de sa version, et
     * la prochaine lecture du catalogue composerait une version nouvelle sans
     * que personne n'ait rien décidé.
     */
    private function rattacherLesOffresExistantes(int $audienceId): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->foreignId('audience_id')->nullable()->after('code')
                ->constrained('audiences')->restrictOnDelete();
        });

        Schema::table('plan_versions', function (Blueprint $table) {
            $table->foreignId('audience_id')->nullable()->after('plan_id')
                ->constrained('audiences')->restrictOnDelete();
        });

        DB::table('plans')->update(['audience_id' => $audienceId]);
        DB::table('plan_versions')->update(['audience_id' => $audienceId]);
    }

    public function down(): void
    {
        Schema::table('plan_versions', function (Blueprint $table) {
            $table->dropForeign(['audience_id']);
            $table->dropColumn('audience_id');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropForeign(['audience_id']);
            $table->dropColumn('audience_id');
        });

        Schema::table('exam_families', function (Blueprint $table) {
            $table->dropForeign(['audience_id']);
            $table->dropColumn('audience_id');
        });

        DB::unprepared('DROP TRIGGER IF EXISTS audiences_code_frozen ON audiences');
        DB::statement('DROP FUNCTION IF EXISTS refuse_audience_code_change()');
        DB::unprepared('DROP TRIGGER IF EXISTS audiences_never_deleted ON audiences');
        DB::statement('DROP FUNCTION IF EXISTS refuse_audience_deletion()');

        Schema::dropIfExists('audiences');
    }
};
