<?php

namespace Tests\Feature\Banque;

use App\Enums\PreparedQuestionState;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\PreparedQuestion;
use App\Models\Question;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportDuCorpusQcmTest extends TestCase
{
    use RefreshDatabase;

    private User $acteur;

    private Exam $se;

    private string $fichier;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->acteur = User::create([
            'email' => 'import@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
        ]);
        $this->acteur->memberships()->create([
            'role_id' => Role::where('code', 'editeur')->whereNull('tenant_id')->value('id'),
        ]);

        $this->se = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->fichier = tempnam(sys_get_temp_dir(), 'corpus').'.json';
        file_put_contents($this->fichier, json_encode($this->corpus(), JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        @unlink($this->fichier);
        parent::tearDown();
    }

    /**
     * Trois lignes suffisent à porter tout ce que le lot doit tenir : une
     * pré-classée, une non classée, et un doublon de la première.
     */
    private function corpus(): array
    {
        $base = [
            'famille' => 'Sciences de l’éducation',
            'voie' => 'Collégial et qualifiant',
            'session' => '2025',
            'discipline' => 'علوم التربية',
            'nb_options' => 5,
            'options' => ['A' => 'أ', 'B' => 'ب', 'C' => 'ج', 'D' => 'د', 'E' => 'هـ'],
            'fiabilite_lecture' => 'claire',
            'statut' => 'a_saisir',
            'difficulte' => 2,
            'temps_s' => 70,
            'priorite' => 0,
            'valeurs_par_defaut' => ['difficulte', 'temps_s'],
            'arbre_cible' => 'SE',
            'doublon_de' => null,
        ];

        return [
            array_merge($base, [
                'id' => 'SUJET_A#Q1', 'sujet' => 'SUJET_A', 'numero' => 'Q1', 'numero_int' => 1,
                'enonce' => 'Première question.', 'page_source' => 3,
                'domaine_code' => 'SE-PSY-LEARN', 'domaine_confiance' => 'haute',
                'domaine_motif' => 'double lecture ar/fr',
                'suggestion_reponse' => 'B',
            ]),
            array_merge($base, [
                'id' => 'SUJET_A#Q2', 'sujet' => 'SUJET_A', 'numero' => 'Q2', 'numero_int' => 2,
                'enonce' => 'Deuxième question, non classée.', 'page_source' => 4,
                'domaine_code' => null, 'domaine_confiance' => null, 'domaine_motif' => null,
                'suggestion_reponse' => null,
            ]),
            array_merge($base, [
                'id' => 'SUJET_B#Q9', 'sujet' => 'SUJET_B', 'numero' => 'Q9', 'numero_int' => 9,
                'enonce' => 'Première question.', 'page_source' => 11,
                'domaine_code' => 'SE-PSY-LEARN', 'domaine_confiance' => 'haute',
                'domaine_motif' => 'double lecture ar/fr',
                'suggestion_reponse' => 'B',
                'doublon_de' => 'SUJET_A#Q1',
            ]),
            // Une ligne d'une autre famille : elle ne doit JAMAIS entrer.
            array_merge($base, [
                'id' => 'AUTRE#Q1', 'sujet' => 'AUTRE', 'numero' => 'Q1', 'numero_int' => 1,
                'famille' => 'Savoirs disciplinaires',
                'enonce' => 'Hors périmètre.', 'page_source' => 1,
                'domaine_code' => null,
            ]),
        ];
    }

    private function importer(array $options = []): int
    {
        return Artisan::call('naja7i:importer-le-corpus-qcm', $options + [
            '--fichier' => $this->fichier,
            '--famille' => 'Sciences de l’éducation',
            '--epreuve' => 'CRMEF-SE-2025',
            '--acteur' => 'import@naja7i.ma',
            '--env' => 'testing',
        ]);
    }

    // --- Ce que le lot existe pour garantir --------------------------------

    /**
     * LE CŒUR DU LOT. Ranger sous une épreuve n'est pas qualifier.
     *
     * Le pré-classement du 15 août n'a été validé par AUCUN expert. L'écrire
     * dans `competency_node_id` le ferait passer pour du travail humain. Il
     * voyage donc dans `provisional`, à côté de sa confiance et de son motif.
     */
    public function test_l_import_range_sous_l_epreuve_et_ne_qualifie_jamais(): void
    {
        $this->assertSame(0, $this->importer());

        $lignes = PreparedQuestion::where('active', true)->get();

        $this->assertCount(3, $lignes, 'La ligne d’une autre famille ne doit pas entrer.');
        $this->assertSame(3, $lignes->where('exam_id', $this->se->id)->count());
        $this->assertSame(0, $lignes->whereNotNull('competency_node_id')->count());

        $classee = PreparedQuestion::where('import_ref', 'SUJET_A#Q1')->firstOrFail();
        $this->assertSame('SE-PSY-LEARN', $classee->provisional['domaine_code']);
        $this->assertSame('haute', $classee->provisional['domaine_confiance']);
        $this->assertSame('double lecture ar/fr', $classee->provisional['domaine_motif']);
        $this->assertNull($classee->competency_node_id);
    }

    /** Les 701 coches manuscrites sont une aide, jamais un corrigé. */
    public function test_la_suggestion_manuscrite_ne_devient_jamais_une_reponse(): void
    {
        $this->importer();

        $ligne = PreparedQuestion::where('import_ref', 'SUJET_A#Q1')->firstOrFail();

        $this->assertSame('B', $ligne->proposed_answer);
        $this->assertNull($ligne->confirmed_answer);
        $this->assertNull($ligne->answer_confirmed_by);
    }

    public function test_le_doublon_pointe_son_original_et_ne_se_transferera_pas(): void
    {
        $this->importer();

        $original = PreparedQuestion::where('import_ref', 'SUJET_A#Q1')->firstOrFail();
        $doublon = PreparedQuestion::where('import_ref', 'SUJET_B#Q9')->firstOrFail();

        $this->assertSame(PreparedQuestionState::DUPLICATE, $doublon->state);
        $this->assertSame($original->uuid, $doublon->duplicate_of_ref);
        $this->assertNotSame(PreparedQuestionState::DUPLICATE, $original->state);
    }

    /** Les faits de source entrent intacts, l'arabe compris. */
    public function test_les_faits_de_source_traversent_sans_alteration(): void
    {
        $this->importer();

        $faits = PreparedQuestion::where('import_ref', 'SUJET_A#Q1')->firstOrFail()->source_facts;

        $this->assertSame('علوم التربية', $faits['discipline']);
        $this->assertSame('هـ', $faits['options']['E']);
        $this->assertSame('Q1', $faits['numero']);
        $this->assertSame(3, $faits['page_source']);
    }

    /** Relancée, elle ne double rien : `import_ref` + empreinte font foi. */
    public function test_un_second_import_du_meme_fichier_ne_double_rien(): void
    {
        $this->importer();
        $this->importer();

        $this->assertSame(3, PreparedQuestion::where('active', true)->count());
    }

    // --- Les gardes --------------------------------------------------------

    public function test_l_import_refuse_sans_environnement_nomme(): void
    {
        $code = Artisan::call('naja7i:importer-le-corpus-qcm', [
            '--fichier' => $this->fichier,
            '--famille' => 'Sciences de l’éducation',
            '--epreuve' => 'CRMEF-SE-2025',
            '--acteur' => 'import@naja7i.ma',
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('env_absent=1', Artisan::output());
        $this->assertSame(0, PreparedQuestion::count());
    }

    public function test_l_import_refuse_une_epreuve_inconnue_et_donne_la_liste(): void
    {
        $code = $this->importer(['--epreuve' => 'CRMEF-INEXISTANTE']);
        $sortie = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('epreuve_inconnue=CRMEF-INEXISTANTE', $sortie);
        $this->assertStringContainsString('CRMEF-SE-2025', $sortie);
        $this->assertSame(0, PreparedQuestion::count());
    }

    public function test_la_simulation_n_ecrit_rien(): void
    {
        $code = $this->importer(['--simulation' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('mode=simulation', Artisan::output());
        $this->assertSame(0, PreparedQuestion::count());
    }

    /**
     * L'INVARIANT VIT DANS LE SCHÉMA.
     *
     * Une ligne rangée sous une épreuve mais qualifiée sur le nœud d'une autre
     * est un mensonge silencieux : les deux champs ont l'air renseignés. Le
     * trigger croise les deux tables — ce qu'un CHECK ne peut pas faire.
     */
    public function test_le_schema_refuse_un_noeud_d_une_autre_epreuve(): void
    {
        $this->importer();

        $ligne = PreparedQuestion::where('import_ref', 'SUJET_A#Q2')->firstOrFail();
        $ailleurs = CompetencyNode::where('exam_id', '!=', $this->se->id)->firstOrFail();

        /*
         * LA TRACE DE QUALIFICATION EST POSÉE EXPRÈS.
         *
         * `prepared_questions_qualification_trace` exige `qualified_by` et
         * `qualified_at` dès qu'un nœud est présent. Sans eux, l'écriture
         * échouerait sur CETTE contrainte-là, et le test passerait au vert
         * sans avoir jamais sollicité le trigger — il l'a fait, la sonde l'a
         * montré. On satisfait donc tout ce qui n'est pas le sujet, pour que
         * le seul refus possible soit celui qu'on mesure.
         */
        $this->expectException(QueryException::class);

        /* SAVEPOINT : une contrainte qui pète empoisonne la transaction de
           RefreshDatabase, et les tests suivants tomberaient sur « current
           transaction is aborted ». */
        DB::transaction(fn () => DB::table('prepared_questions')
            ->where('id', $ligne->id)
            ->update([
                'competency_node_id' => $ailleurs->id,
                'qualified_by' => $this->acteur->id,
                'qualified_at' => now(),
                'state' => 'qualified',
            ]));
    }

    // --- Le retrait des brouillons importés (décision A du 24 août) --------

    private function poserUnBrouillonImporte(string $ref = 'SUJET_A|Q1'): Question
    {
        return Question::create([
            'exam_id' => $this->se->id,
            'competency_node_id' => CompetencyNode::where('exam_id', $this->se->id)->firstOrFail()->id,
            'locale' => 'ar',
            'sibling_group' => (string) Str::uuid7(),
            'stem' => 'Un brouillon posé par l’ancien chemin d’import.',
            'status' => 'draft',
            'authoring' => 'imported',
            'eligible_for_diagnostic' => false,
            'eligible_for_simulation' => false,
            'import_ref' => $ref,
        ]);
    }

    private function retirer(array $options = []): int
    {
        return Artisan::call('naja7i:retirer-les-questions-importees', $options + ['--env' => 'testing']);
    }

    /** Le défaut est de NE RIEN FAIRE : le geste ne se défait pas. */
    public function test_le_retrait_annonce_et_ne_supprime_rien_sans_confirmation(): void
    {
        $this->poserUnBrouillonImporte();

        $this->assertSame(0, $this->retirer());
        $this->assertStringContainsString('visees=1', Artisan::output());
        $this->assertSame(1, Question::where('authoring', 'imported')->count());
    }

    public function test_le_retrait_supprime_le_brouillon_et_ses_options(): void
    {
        $question = $this->poserUnBrouillonImporte();

        $this->assertSame(0, $this->retirer(['--confirmer' => true]));
        $this->assertSame(0, Question::where('id', $question->id)->count());
    }

    /**
     * LA GARDE QUI COMPTE. Une question tenue par la zone de préparation a été
     * transférée : c'est la chaîne éditoriale qui en répond, pas une commande.
     *
     * La garde des questions déjà servies (`attempt_items`) partage exactement
     * cette branche de refus — c'est le même `if`, et le même refus en bloc.
     */
    public function test_le_retrait_refuse_en_bloc_une_question_tenue_par_la_preparation(): void
    {
        $question = $this->poserUnBrouillonImporte();
        $this->importer();

        PreparedQuestion::where('import_ref', 'SUJET_A#Q2')->firstOrFail()
            ->forceFill([
                'state' => PreparedQuestionState::TRANSFERRED,
                'question_id' => $question->id,
            ])->save();

        $code = $this->retirer(['--confirmer' => true]);
        $sortie = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('tenues_par_la_preparation=1', $sortie);
        $this->assertStringContainsString('Refusé', $sortie);
        $this->assertSame(1, Question::where('id', $question->id)->count());
    }

    public function test_le_retrait_ne_touche_jamais_une_question_ecrite_par_un_humain(): void
    {
        $importee = $this->poserUnBrouillonImporte();
        $ecrite = $this->poserUnBrouillonImporte('SUJET_A|Q2');
        $ecrite->forceFill(['authoring' => 'human', 'import_ref' => null])->save();

        $this->retirer(['--confirmer' => true]);

        $this->assertSame(0, Question::where('id', $importee->id)->count());
        $this->assertSame(1, Question::where('id', $ecrite->id)->count());
    }

    public function test_le_retrait_refuse_sans_environnement_nomme(): void
    {
        $this->poserUnBrouillonImporte();

        $code = Artisan::call('naja7i:retirer-les-questions-importees', ['--confirmer' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('env_absent=1', Artisan::output());
        $this->assertSame(1, Question::where('authoring', 'imported')->count());
    }
}
