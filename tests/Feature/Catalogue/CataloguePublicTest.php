<?php

namespace Tests\Feature\Catalogue;

use App\Models\CompetencyNode;
use App\Models\ExamFamily;
use App\Models\ExamSession;
use App\Models\TaxonomyProfile;
use Database\Seeders\CatalogueSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CataloguePublicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogueSeeder::class);
    }

    // --- Accès public ------------------------------------------------------

    public function test_le_catalogue_est_lisible_sans_compte(): void
    {
        $this->getJson('/api/v1/catalogue')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.slug', 'sciences-education');
    }

    public function test_une_famille_expose_ses_specialites_et_sa_taxonomie(): void
    {
        $this->getJson('/api/v1/catalogue/familles/crmef')
            ->assertOk()
            ->assertJsonPath('data.slug', 'crmef')
            ->assertJsonCount(2, 'data.specialties')
            ->assertJsonPath('data.taxonomy.levels.0.name', 'Pilier')
            ->assertJsonPath('data.taxonomy.levels.3.name', 'Microcompétence');
    }

    public function test_une_specialite_est_accessible_par_son_slug(): void
    {
        $this->getJson('/api/v1/catalogue/familles/crmef/specialites/francais')
            ->assertOk()
            ->assertJsonPath('data.slug', 'francais')
            ->assertJsonPath('data.family.slug', 'crmef');
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
        $this->withHeaders(['Accept-Language' => 'ar'])
            ->getJson('/api/v1/catalogue/familles/crmef')
            ->assertOk()
            ->assertJsonPath('data.taxonomy.levels.0.name', 'ركيزة')
            ->assertJsonPath('data.specialties.0.name', 'الفرنسية');
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
