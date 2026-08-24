<?php

namespace Tests\Feature\Catalogue;

use App\Models\CompetencyNode;
use App\Models\ExamFamily;
use App\Models\ExamSession;
use App\Models\TaxonomyProfile;
use App\Models\Track;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class CataloguePublicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    // --- Accès public ------------------------------------------------------

    public function test_le_catalogue_est_lisible_sans_compte(): void
    {
        $this->getJson('/api/v1/catalogue')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.slug', 'sciences-education');
    }

    /**
     * Seize spécialités : onze au secondaire, quatre au primaire bilingue, une
     * au primaire amazigh.
     *
     * Cette classe attendait DEUX spécialités, parce qu'elle ne semait que
     * `CatalogueSeeder`. Or `Crmef2025Seeder::purgerSpecialitesOrphelines()`
     * SUPPRIME les entrées provisoires du PAS-4 (« francais »,
     * « mathematiques ») que le référentiel officiel remplace — sans quoi le
     * candidat verrait deux entrées pour la même discipline. Le test tenait
     * donc un état intermédiaire que ni la production ni `migrate --seed` ne
     * produisent.
     */
    public function test_une_famille_expose_ses_specialites_et_sa_taxonomie(): void
    {
        $this->getJson('/api/v1/catalogue/familles/crmef')
            ->assertOk()
            ->assertJsonPath('data.slug', 'crmef')
            ->assertJsonCount(16, 'data.specialties')
            ->assertJsonPath('data.taxonomy.levels.0.name', 'Pilier')
            ->assertJsonPath('data.taxonomy.levels.3.name', 'Microcompétence');
    }

    public function test_une_specialite_est_accessible_par_son_slug(): void
    {
        /*
         * Le slug PORTE SON PARCOURS depuis DET-101. Les assertions vérifient
         * le cycle et la disponibilité, et pas seulement le slug : c'est
         * exactement ce qui manquait à ce test le jour où il a laissé passer
         * l'incident. « langue-francaise » matchait les DEUX lignes en
         * collision, et `slug` comme `family.slug` étaient identiques sur les
         * deux — l'assertion ne pouvait pas voir laquelle répondait.
         */
        $this->getJson('/api/v1/catalogue/familles/crmef/specialites/langue-francaise-secondaire')
            ->assertOk()
            ->assertJsonPath('data.slug', 'langue-francaise-secondaire')
            ->assertJsonPath('data.family.slug', 'crmef')
            ->assertJsonPath('data.cycle', 'Secondaire collégial et qualifiant')
            ->assertJsonPath('data.availability', 'open');
    }

    /**
     * LE TEST QUE DET-101 RÉCLAMAIT.
     *
     * « Langue française » existe sous le secondaire ET sous le primaire
     * bilingue. Avant ce pas, les deux entrées de la liste pointaient la même
     * adresse, et cette adresse rendait le primaire : le candidat qui cliquait
     * « ouvert » lisait « liste d'attente », sans bouton de diagnostic. La
     * seule spécialité ouverte du pilote n'était atteignable par aucune URL.
     *
     * Ce test échoue si les deux slugs se confondent à nouveau — c'est le seul
     * qui distingue les deux lignes par autre chose que leur slug.
     */
    public function test_une_meme_discipline_sous_deux_parcours_donne_deux_pages(): void
    {
        $secondaire = $this->getJson(
            '/api/v1/catalogue/familles/crmef/specialites/langue-francaise-secondaire'
        )->assertOk();

        $primaire = $this->getJson(
            '/api/v1/catalogue/familles/crmef/specialites/langue-francaise-primaire-bilingue'
        )->assertOk();

        $this->assertNotSame(
            $secondaire->json('data.uuid'),
            $primaire->json('data.uuid'),
            'Les deux parcours doivent rendre deux lignes distinctes.'
        );

        $this->assertSame('Secondaire collégial et qualifiant', $secondaire->json('data.cycle'));
        $this->assertSame('Primaire bilingue', $primaire->json('data.cycle'));

        // Le fait qui a coûté l'incident : l'OUVERTE est atteignable.
        $this->assertSame('open', $secondaire->json('data.availability'));
        $this->assertSame('waitlist', $primaire->json('data.availability'));
    }

    /**
     * L'INVARIANT VIT DANS LE SCHÉMA, pas dans ce fichier.
     *
     * Un test peut être oublié au prochain ajout au référentiel ; un index
     * unique, non. Celui-ci prouve que la contrainte existe et qu'elle mord —
     * les deux lignes visent deux parcours différents, donc `(track_id, slug)`
     * ne les sépare pas : seule `(exam_family_id, slug)` refuse.
     */
    public function test_le_schema_refuse_deux_specialites_de_meme_slug_dans_une_famille(): void
    {
        $crmef = ExamFamily::where('slug', 'crmef')->firstOrFail();
        $autre = Track::where('slug', 'primaire-amazigh')->firstOrFail();

        $this->expectException(QueryException::class);

        /* Transaction imbriquée : une contrainte qui pète empoisonne la
           transaction de RefreshDatabase, et les tests suivants tomberaient
           sur « current transaction is aborted ». Le SAVEPOINT la contient. */
        DB::transaction(fn () => DB::table('specialties')->insert([
            'uuid' => (string) Str::uuid7(),
            'exam_family_id' => $crmef->id,
            'track_id' => $autre->id,
            'slug' => 'langue-francaise-secondaire',   // déjà pris sous un AUTRE parcours
            'name_fr' => 'Doublon', 'name_ar' => 'مكرر',
            'status' => 'published', 'published_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_une_specialite_provisoire_du_pas_4_ne_survit_pas_au_referentiel(): void
    {
        $this->getJson('/api/v1/catalogue/familles/crmef/specialites/francais')
            ->assertNotFound();
    }

    // --- Rien de non publié ne fuit ---------------------------------------

    public function test_un_brouillon_n_apparait_pas_dans_le_catalogue(): void
    {
        ExamFamily::where('slug', 'crmef')->update(['status' => 'draft']);

        $this->getJson('/api/v1/catalogue/familles/crmef')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_une_publication_datee_dans_le_futur_reste_invisible(): void
    {
        ExamFamily::where('slug', 'crmef')->update(['published_at' => now()->addWeek()]);

        $this->getJson('/api/v1/catalogue/familles/crmef')->assertStatus(404);
    }

    public function test_une_ressource_inexistante_repond_404_pas_403(): void
    {
        $this->getJson('/api/v1/catalogue/familles/concours-secret')
            ->assertStatus(404)
            ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
    }

    // --- Calendrier --------------------------------------------------------

    public function test_le_calendrier_signale_toujours_si_une_date_est_confirmee(): void
    {
        $this->getJson('/api/v1/catalogue/calendrier')
            ->assertOk()
            ->assertJsonPath('data.0.dates_confirmed', false)
            ->assertJsonStructure(['data' => [['dates_confirmed', 'source_note', 'year']]]);
    }

    public function test_le_calendrier_se_filtre_par_famille(): void
    {
        $this->getJson('/api/v1/catalogue/calendrier?famille=crmef')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/catalogue/calendrier?famille=encg')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_une_session_passee_n_apparait_pas(): void
    {
        ExamSession::query()->update(['written_exam_on' => now()->subYear()]);

        $this->getJson('/api/v1/catalogue/calendrier')->assertJsonCount(0, 'data');
    }

    // --- Bilinguisme -------------------------------------------------------

    public function test_le_catalogue_repond_en_arabe(): void
    {
        $reponse = $this->withHeaders(['Accept-Language' => 'ar'])
            ->getJson('/api/v1/catalogue/familles/crmef')
            ->assertOk()
            ->assertJsonPath('data.taxonomy.levels.0.name', 'ركيزة');

        /* Repérée par son slug et non par son rang : l'ordre d'une liste de
         * seize spécialités n'est pas ce que ce test défend, et s'y accrocher
         * le ferait tomber au prochain ajout au référentiel. */
        $langueFrancaise = collect($reponse->json('data.specialties'))
            ->firstWhere('slug', 'langue-francaise-secondaire');

        $this->assertSame('اللغة الفرنسية', $langueFrancaise['name']);
    }

    // --- Taxonomie en arbre (ADR-0012) ------------------------------------

    public function test_les_competences_sortent_en_arbre_avec_les_noms_de_niveaux(): void
    {
        $this->getJson('/api/v1/catalogue/familles/crmef/competences')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.depth', 0)
            ->assertJsonPath('data.0.level_name', 'Pilier')
            ->assertJsonPath('meta.levels.1.name', 'Domaine');
    }

    public function test_une_famille_peut_avoir_une_profondeur_differente(): void
    {
        // Un concours dont le cadre de référence compte trois niveaux,
        // nommés autrement : aucune migration ne doit être nécessaire.
        $medecine = ExamFamily::where('slug', 'medecine')->firstOrFail();

        TaxonomyProfile::create([
            'exam_family_id' => $medecine->id,
            'levels' => [
                ['name_fr' => 'Unité', 'name_ar' => 'وحدة'],
                ['name_fr' => 'Chapitre', 'name_ar' => 'فصل'],
                ['name_fr' => 'Objectif', 'name_ar' => 'هدف'],
            ],
            'min_depth_for_publication' => 2,
        ]);

        $unite = CompetencyNode::create([
            'exam_family_id' => $medecine->id, 'code' => 'U1',
            'name_fr' => 'Biologie cellulaire', 'name_ar' => 'بيولوجيا الخلية',
        ]);

        $this->assertSame(0, $unite->depth);
        $this->assertSame('Unité', $unite->fresh()->levelName());
    }

    public function test_la_profondeur_et_le_chemin_se_calculent_seuls(): void
    {
        $crmef = ExamFamily::where('slug', 'crmef')->firstOrFail();
        $pilier = CompetencyNode::where('code', 'SE')->firstOrFail();

        $domaine = CompetencyNode::create([
            'exam_family_id' => $crmef->id, 'parent_id' => $pilier->id,
            'code' => 'SE.1', 'name_fr' => 'Évaluation', 'name_ar' => 'التقويم',
        ]);

        $competence = CompetencyNode::create([
            'exam_family_id' => $crmef->id, 'parent_id' => $domaine->id,
            'code' => 'SE.1.1', 'name_fr' => 'Construire une évaluation', 'name_ar' => 'بناء تقويم',
        ]);

        $this->assertSame(1, $domaine->fresh()->depth);
        $this->assertSame(2, $competence->fresh()->depth);
        $this->assertSame(
            "{$pilier->id}.{$domaine->id}.{$competence->id}",
            $competence->fresh()->path
        );
    }

    public function test_le_sous_arbre_se_recupere_en_une_requete(): void
    {
        $crmef = ExamFamily::where('slug', 'crmef')->firstOrFail();
        $pilier = CompetencyNode::where('code', 'SE')->firstOrFail();

        $domaine = CompetencyNode::create([
            'exam_family_id' => $crmef->id, 'parent_id' => $pilier->id,
            'code' => 'SE.2', 'name_fr' => 'Planification', 'name_ar' => 'التخطيط',
        ]);
        CompetencyNode::create([
            'exam_family_id' => $crmef->id, 'parent_id' => $domaine->id,
            'code' => 'SE.2.1', 'name_fr' => 'Séquence', 'name_ar' => 'مقطع',
        ]);

        $this->assertSame(2, CompetencyNode::descendantsOf($pilier->fresh())->count());
    }

    public function test_un_noeud_ne_peut_pas_devenir_son_propre_ancetre(): void
    {
        $crmef = ExamFamily::where('slug', 'crmef')->firstOrFail();
        $pilier = CompetencyNode::where('code', 'SE')->firstOrFail();

        $enfant = CompetencyNode::create([
            'exam_family_id' => $crmef->id, 'parent_id' => $pilier->id,
            'code' => 'SE.9', 'name_fr' => 'Test', 'name_ar' => 'اختبار',
        ]);

        $this->expectException(RuntimeException::class);

        $pilier->parent_id = $enfant->id;
        $pilier->save();
    }

    public function test_un_noeud_ne_peut_pas_avoir_un_parent_d_une_autre_famille(): void
    {
        $medecine = ExamFamily::where('slug', 'medecine')->firstOrFail();
        $pilier = CompetencyNode::where('code', 'SE')->firstOrFail();

        $this->expectException(QueryException::class);

        CompetencyNode::create([
            'exam_family_id' => $medecine->id, 'parent_id' => $pilier->id,
            'code' => 'X1', 'name_fr' => 'Intrus', 'name_ar' => 'دخيل',
        ]);
    }

    public function test_un_profil_ne_peut_pas_depasser_six_niveaux(): void
    {
        $encg = ExamFamily::where('slug', 'encg')->firstOrFail();

        $this->expectException(QueryException::class);

        TaxonomyProfile::create([
            'exam_family_id' => $encg->id,
            'levels' => array_fill(0, 7, ['name_fr' => 'Niveau', 'name_ar' => 'مستوى']),
        ]);
    }

    // --- Isolation : le catalogue est global ------------------------------

    public function test_aucune_table_de_catalogue_ne_porte_tenant_id(): void
    {
        $tables = ['filieres', 'exam_families', 'specialties', 'exam_sessions',
            'taxonomy_profiles', 'competency_nodes'];

        foreach ($tables as $table) {
            $this->assertFalse(
                \Schema::hasColumn($table, 'tenant_id'),
                "La table {$table} appartient au catalogue : elle ne doit jamais porter tenant_id (ADR-0002, ADR-0013)."
            );
        }
    }

    public function test_aucune_cle_interne_dans_les_reponses_du_catalogue(): void
    {
        foreach ([
            '/api/v1/catalogue',
            '/api/v1/catalogue/familles/crmef',
            '/api/v1/catalogue/familles/crmef/competences',
            '/api/v1/catalogue/calendrier',
        ] as $url) {
            $corps = $this->getJson($url)->content();

            foreach (['"id":', '"filiere_id"', '"exam_family_id"', '"parent_id"'] as $interdit) {
                $this->assertStringNotContainsString($interdit, $corps, "{$interdit} exposé sur {$url}");
            }
        }
    }
}
