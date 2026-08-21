<?php

namespace Tests\Feature\Banque;

use App\Models\CompetencyNode;
use App\Models\Question;
use App\Services\QcmCorpusDryRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class QcmCorpusDryRunTest extends TestCase
{
    use RefreshDatabase;

    private const CORPUS_REEL = '/Users/Redouan/Coding/questions/naja7i_qcm_a_valider.json';

    public function test_le_dry_run_projette_sans_ecrire_et_sans_inventer_de_reponse(): void
    {
        $before = Question::count();
        $report = app(QcmCorpusDryRun::class)->analyser(base_path('tests/Fixtures/qcm_corpus_dry_run.json'));

        $this->assertTrue($report['valid_json']);
        $this->assertSame(3, $report['lignes']);
        $this->assertSame(1, $report['sujets']);
        $this->assertSame(2, $report['suggestions']);
        $this->assertSame(1, $report['suggestions_ambigues']);
        $this->assertSame(1, $report['doublons']);
        $this->assertSame(1, $report['sources_illisibles']);
        $this->assertSame(1, $report['contextes_markdown']);
        $this->assertSame(1, $report['fiabilites_multilignes']);
        $this->assertFalse($report['import_ready']);
        $this->assertSame(0, $report['deja_presents']);
        $this->assertSame($before, Question::count(), 'Une simulation ne doit écrire aucune question.');

        $first = $report['mapping'][0];
        $this->assertCount(4, $first['options']);
        $this->assertSame('الأول', $first['options'][0]['content']);
        $this->assertStringNotContainsString('A.', $first['options'][0]['content']);
        $this->assertSame('ar', $first['question']['locale']);
        $this->assertNull($first['question']['difficulty']);
        $this->assertSame(2, $first['question']['import_metadata']['provisional']['difficulte']);
        $this->assertSame('B', $first['question']['import_metadata']['source_facts']['suggestion_reponse']);
        $this->assertNotEmpty($first['question']['exam_code']);
        $this->assertTrue(collect($first['options'])->every(fn ($option) => $option['is_correct'] === false));
        $this->assertArrayHasKey('options', $first['question']['import_metadata']['source_facts']);
    }

    public function test_un_contexte_infiltre_est_bloque_et_conserve_sans_troncature(): void
    {
        $report = app(QcmCorpusDryRun::class)->analyser(base_path('tests/Fixtures/qcm_corpus_dry_run.json'));
        $second = $report['mapping'][1];
        $preserved = $second['question']['import_metadata']['parser_trace']['preserved_text'];

        $this->assertFalse($second['importable']);
        $this->assertStringContainsString('## Texte support — ne pas perdre', $preserved);
        $this->assertContains(
            'contexte_markdown_dans_fiabilite',
            collect($report['anomalies'])->pluck('code')->all()
        );
    }

    public function test_la_commande_est_une_simulation_sans_ecriture(): void
    {
        $before = Question::count();

        $this->artisan('qcm:verifier', [
            'fichier' => base_path('tests/Fixtures/qcm_corpus_dry_run.json'),
        ])->assertSuccessful();

        $this->assertSame($before, Question::count());
    }

    public function test_le_mode_strict_refuse_un_corpus_valide_mais_non_pret(): void
    {
        $this->artisan('qcm:verifier', [
            'fichier' => base_path('tests/Fixtures/qcm_corpus_dry_run.json'),
            '--strict' => true,
        ])->assertFailed();
    }

    public function test_import_metadata_n_est_pas_assignable_par_un_chemin_ordinaire(): void
    {
        $question = new Question;
        $question->fill(['stem' => 'Essai', 'import_metadata' => ['interdit' => true]]);

        $this->assertSame('Essai', $question->stem);
        $this->assertNull($question->import_metadata);
    }

    public function test_import_metadata_n_est_jamais_expose_par_la_serialisation_du_modele(): void
    {
        $question = new Question;
        $question->setAttribute('import_metadata', [
            'source_facts' => ['suggestion_reponse' => 'B'],
        ]);

        $this->assertArrayNotHasKey('import_metadata', $question->toArray());
    }

    public function test_la_difficulte_cinq_appartient_a_l_echelle_pedagogique_declaree(): void
    {
        $rows = json_decode(file_get_contents(base_path('tests/Fixtures/qcm_corpus_dry_run.json')), true);
        $rows[0]['difficulte'] = 5;
        $path = tempnam(sys_get_temp_dir(), 'qcm-difficulty-five-');
        file_put_contents($path, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        try {
            $report = app(QcmCorpusDryRun::class)->analyser($path);
            $issues = collect($report['anomalies'])
                ->where('id', 'FIXTURE#Q1')
                ->pluck('code');

            $this->assertNotContains('difficulte_invalide', $issues);
            $this->assertNull($report['mapping'][0]['question']['difficulty']);
            $this->assertSame(5, $report['mapping'][0]['question']['import_metadata']['provisional']['difficulte']);
        } finally {
            unlink($path);
        }
    }

    public function test_les_metadonnees_provisoires_invalides_sont_signalees_sans_correction(): void
    {
        $rows = json_decode(file_get_contents(base_path('tests/Fixtures/qcm_corpus_dry_run.json')), true);
        $rows[0]['suggestion_reponse'] = 'E'; // La sentinelle E est vide sur ce QCM à quatre choix.
        $rows[0]['difficulte'] = 6;
        $rows[0]['temps_s'] = 12;
        $rows[0]['valeurs_par_defaut'] = ['difficulte', 'inconnu'];
        $rows[0]['statut'] = 'publie';
        $path = tempnam(sys_get_temp_dir(), 'qcm-invalid-');
        file_put_contents($path, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        try {
            $report = app(QcmCorpusDryRun::class)->analyser($path);
            $codes = collect($report['anomalies'])->pluck('code');

            $this->assertContains('suggestion_reponse_invalide', $codes);
            $this->assertContains('difficulte_invalide', $codes);
            $this->assertContains('temps_s_invalide', $codes);
            $this->assertContains('valeurs_par_defaut_invalides', $codes);
            $this->assertContains('statut_inconnu', $codes);
            $this->assertSame('E', $report['mapping'][0]['question']['import_metadata']['source_facts']['suggestion_reponse']);
            $this->assertFalse($report['mapping'][0]['importable']);
        } finally {
            unlink($path);
        }
    }

    public function test_une_simple_note_multiligne_n_est_pas_confondue_avec_un_contexte_markdown(): void
    {
        $rows = json_decode(file_get_contents(base_path('tests/Fixtures/qcm_corpus_dry_run.json')), true);
        $rows[1]['fiabilite_lecture'] = "claire\n\nMention finale imprimée.";
        $path = tempnam(sys_get_temp_dir(), 'qcm-multiline-');
        file_put_contents($path, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        try {
            $report = app(QcmCorpusDryRun::class)->analyser($path);

            $this->assertSame(1, $report['fiabilites_multilignes']);
            $this->assertSame(0, $report['contextes_markdown']);
            $this->assertSame(1, $report['multilignes_non_bloquants']);
        } finally {
            unlink($path);
        }
    }

    public function test_un_import_ref_deja_present_est_signale_sans_etre_recree(): void
    {
        $node = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();
        Question::create([
            'exam_id' => $node->exam_id,
            'competency_node_id' => $node->id,
            'locale' => 'ar',
            'stem' => 'Question déjà connue.',
            'authoring' => 'imported',
            'import_ref' => 'FIXTURE#Q1',
        ]);

        $report = app(QcmCorpusDryRun::class)->analyser(base_path('tests/Fixtures/qcm_corpus_dry_run.json'));

        $this->assertSame(1, $report['deja_presents']);
        $this->assertTrue($report['mapping'][0]['deja_present']);
        $this->assertSame(1, Question::where('import_ref', 'FIXTURE#Q1')->count());
    }

    public function test_le_corpus_reel_garde_ses_invariants_mesures(): void
    {
        if (! is_file(self::CORPUS_REEL)) {
            $this->markTestSkipped('Le corpus externe immuable n’est pas disponible sur cette machine.');
        }

        $report = app(QcmCorpusDryRun::class)->analyser(self::CORPUS_REEL);

        $this->assertSame(1413, $report['lignes']);
        $this->assertSame(29, $report['sujets']);
        $this->assertSame(701, $report['suggestions']);
        $this->assertSame(22, $report['suggestions_ambigues']);
        $this->assertSame(75, $report['doublons']);
        $this->assertSame(5, $report['sources_illisibles']);
        $this->assertSame(0, count($report['rejets']));
        $this->assertSame(1413, $report['projections']);
        $this->assertSame(31, $report['fiabilites_multilignes']);
        $this->assertSame(27, $report['contextes_markdown']);
        $this->assertSame(4, $report['multilignes_non_bloquants']);
        $this->assertSame([
            'separateur_markdown' => 26,
            'titre_markdown_niveau_2' => 25,
            'libelle_texte_support' => 24,
        ], $report['motifs_contextes']);
    }
}
