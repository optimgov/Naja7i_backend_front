<?php

namespace Tests\Feature\Simulation;

use App\Contracts\AccessGrant;
use App\Exceptions\AttemptExpired;
use App\Exceptions\IdempotencyKeyReused;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\ReviewSchedule;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\OuvreLesDroits;
use Tests\TestCase;

/**
 * L'examen blanc : composition, chronomètre, concurrence, rapport.
 *
 * L'épreuve de test porte 6 sous-domaines pondérés, somme 100, et une durée
 * officielle de 120 minutes — sans elle, aucun examen blanc n'est ouvrable, et
 * c'est le premier test.
 */
class SimulationTest extends TestCase
{
    use OuvreLesDroits, RefreshDatabase;

    private Exam $epreuve;

    private User $candidat;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->candidat = $this->compte('candidat@naja7i.ma');

        /* L'examen blanc se vend depuis 3A.9 : ce fichier éprouve ce qu'il
         * compose et ce qu'il rapporte, pas le mur qui l'ouvre. */
        $this->ouvrirLesDroits($this->candidat, AccessGrant::SIMULATOR_FULL);

        $this->peuplerBanque(6);
    }

    private function compte(string $email): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $user->markEmailAsVerified();
        $user->grantCandidateRole();

        return $user;
    }

    private function peuplerBanque(int $parSousDomaine): void
    {
        $valideur = $this->compte('valideur@naja7i.ma');
        $source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();
        $transitions = app(QuestionTransitionService::class);

        foreach (CompetencyNode::where('exam_id', $this->epreuve->id)->where('depth', 1)->get() as $noeud) {
            $remediation = Remediation::create([
                'competency_node_id' => $noeud->id, 'locale' => 'fr',
                'title' => "Remédiation {$noeud->code}", 'content' => 'Contenu.',
                'estimated_minutes' => 8, 'status' => 'published',
            ]);

            for ($i = 1; $i <= $parSousDomaine; $i++) {
                $question = Question::create([
                    'exam_id' => $this->epreuve->id, 'competency_node_id' => $noeud->id,
                    'locale' => 'fr', 'sibling_group' => (string) Str::uuid7(),
                    'stem' => "Énoncé {$i} — {$noeud->code}",
                    'explanation' => 'JUSTIFICATION_SECRETE',
                    'remediation_id' => $remediation->id,
                ]);

                foreach ([
                    ['Option A', false, 'RATIONALE_SECRETE_A', 'confusion_notions'],
                    ['Option B', true,  'RATIONALE_SECRETE_B', null],
                    ['Option C', false, 'RATIONALE_SECRETE_C', 'lecture_enonce'],
                    ['Option D', false, 'RATIONALE_SECRETE_D', 'connaissance_absente'],
                    ['Aucune des propositions précédentes', false, 'Elle est fausse puisqu’une autre proposition est correcte.', 'indetermine'],
                ] as $p => [$c, $juste, $justif, $cause]) {
                    QuestionOption::create([
                        'question_id' => $question->id, 'position' => $p + 1,
                        'content' => $c, 'is_correct' => $juste, 'rationale' => $justif, 'cause' => $cause,
                    ]);
                }

                $question->contentSources()->attach($source->id, ['verification' => 'verified']);

                $transitions->submitForReview($question);
                $transitions->markReviewed($question, $this->relecteurDeControle());
                $transitions->validate($question, $valideur);
                $transitions->publish($question, forDiagnostic: true);
            }
        }
    }

    private function service(): AttemptService
    {
        return app(AttemptService::class);
    }

    private function ouvrir(int $total = 12): Attempt
    {
        return $this->service()->startSimulation(
            $this->candidat, $this->epreuve, 'fr', (string) Str::uuid7(), $total
        )['attempt'];
    }

    // ══════════════════════════════ 1. La composition suit le BLUEPRINT ═══

    public function test_la_composition_suit_les_poids_officiels_et_non_la_maitrise(): void
    {
        /*
         * LE TEST CENTRAL DU LOT, et il est écrit pour qu'une seule mutation le
         * fasse rougir : composer depuis l'ordonnance au lieu des poids.
         *
         * On donne d'abord au candidat une FAIBLESSE FRANCHE sur un domaine —
         * c'est ce que l'ordonnance regarde. Un composeur d'entraînement
         * concentrerait la série dessus ; un composeur d'examen blanc doit
         * l'ignorer complètement et suivre `weight_percent`.
         */
        $noeuds = CompetencyNode::where('exam_id', $this->epreuve->id)
            ->where('depth', 1)->whereNotNull('weight_percent')->orderBy('id')->get();

        $faible = $noeuds->sortBy('weight_percent')->first();

        // Une faiblesse démontrée et datée sur le domaine le PLUS LÉGER : si la
        // composition suivait la maîtrise, ce domaine dominerait la série.
        ReviewSchedule::create([
            'user_id' => $this->candidat->id, 'exam_id' => $this->epreuve->id,
            'competency_node_id' => $faible->id, 'cause' => 'connaissance_absente',
            'palier' => 1, 'due_on' => now()->subDay()->toDateString(), 'blind_error' => true,
        ]);

        $attempt = $this->ouvrir(12);

        $parNoeud = AttemptItem::where('attempt_id', $attempt->id)
            ->get()->groupBy('competency_node_id')->map->count();

        $sommeDesPoids = (float) $noeuds->sum('weight_percent');

        foreach ($noeuds as $noeud) {
            $attendu = ((float) $noeud->weight_percent / $sommeDesPoids) * 12;
            $servi = $parNoeud->get($noeud->id, 0);

            /* Plus forts restes : l'écart à la part exacte est toujours < 1. */
            $this->assertLessThan(
                1.0,
                abs($servi - $attendu),
                "Le domaine {$noeud->code} pèse {$noeud->weight_percent}% et a reçu {$servi} question(s) sur 12 "
                    .'— la série ne reproduit pas les poids officiels.'
            );
        }

        $this->assertSame(12, $attempt->item_count);
    }

    public function test_la_faiblesse_du_candidat_ne_deforme_pas_la_serie(): void
    {
        // Formulation complémentaire de la même règle, côté effet : deux
        // candidats aux maîtrises opposées reçoivent la MÊME répartition.
        $autre = $this->compte('autre@naja7i.ma');

        $noeud = CompetencyNode::where('exam_id', $this->epreuve->id)
            ->where('depth', 1)->whereNotNull('weight_percent')->orderBy('id')->first();

        ReviewSchedule::create([
            'user_id' => $this->candidat->id, 'exam_id' => $this->epreuve->id,
            'competency_node_id' => $noeud->id, 'cause' => 'connaissance_absente',
            'palier' => 1, 'due_on' => now()->subDay()->toDateString(), 'blind_error' => true,
        ]);

        $a = $this->ouvrir(12);
        $b = $this->service()->startSimulation($autre, $this->epreuve, 'fr', (string) Str::uuid7(), 12)['attempt'];

        $repartition = fn (Attempt $x) => AttemptItem::where('attempt_id', $x->id)
            ->get()->groupBy('competency_node_id')->map->count()->sortKeys()->values()->all();

        $this->assertSame($repartition($a), $repartition($b));
    }

    // ══════════════════════════════════════ 2. Le chronomètre est serveur ═══

    public function test_sans_duree_officielle_aucun_examen_blanc(): void
    {
        /* Le référentiel interdit de déduire une durée : sans elle, on refuse
         * plutôt que d'inventer. C'est la règle la plus chère du projet. */
        $this->epreuve->update(['duration_minutes' => null]);

        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/simulations/{$this->epreuve->code}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SIMULATION_DURATION_UNKNOWN');

        $this->assertSame(0, Attempt::where('kind', 'simulation')->count());
    }

    public function test_l_echeance_vient_de_la_duree_officielle(): void
    {
        $attempt = $this->ouvrir();

        $this->assertNotNull($attempt->expires_at);
        $this->assertEqualsWithDelta(
            $this->epreuve->duration_minutes * 60,
            $attempt->secondsRemaining(),
            5,
        );
    }

    public function test_une_reponse_apres_l_echeance_est_refusee(): void
    {
        $attempt = $this->ouvrir();
        $item = $attempt->items()->first();

        $this->travel($this->epreuve->duration_minutes + 1)->minutes();

        /*
         * `try/finally` ET NON `expectException` : la première écriture posait
         * `travelBack()` APRÈS l'appel qui lève, donc sur une ligne que
         * l'exception rendait inatteignable. L'horloge restait figée, et
         * c'était à un test ultérieur de le payer.
         *
         * Trouvé par l'assertion de fin de test, qui a désigné CE test-ci —
         * c'est exactement ce qu'elle est là pour faire.
         */
        try {
            $this->service()->answer($item, null, 'sure');
            $this->fail('Une réponse après l’échéance devait être refusée.');
        } catch (AttemptExpired) {
            $this->assertTrue(true);
        } finally {
            $this->travelBack();
        }
    }

    public function test_la_reponse_tardive_porte_son_propre_code(): void
    {
        /*
         * CODE DISTINCT DE `ATTEMPT_CLOSED`, et l'ordre de capture en dépend :
         * `AttemptExpired` hérite de `RuntimeException`, donc le capturer après
         * le ferait disparaître. Mutation : inverser les deux `catch` fait
         * rougir ce test.
         */
        $attempt = $this->ouvrir();
        $item = $attempt->items()->first();
        $option = $item->question->options()->first();

        $this->travel($this->epreuve->duration_minutes + 1)->minutes();

        $this->actingAs($this->candidat)
            ->putJson("/api/v1/me/attempts/{$attempt->uuid}/items/{$item->uuid}", [
                'option_uuid' => $option->uuid,
                'confidence' => 'sure',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ATTEMPT_EXPIRED');

        /* L’horloge est rendue : un temps figé se paie dans un test ULTÉRIEUR. */
        $this->travelBack();
    }

    public function test_la_confiance_reste_requise_en_examen_blanc(): void
    {
        // La certitude est l'ADN du produit : l'examen blanc n'y échappe pas.
        $attempt = $this->ouvrir();
        $item = $attempt->items()->first();

        $this->actingAs($this->candidat)
            ->putJson("/api/v1/me/attempts/{$attempt->uuid}/items/{$item->uuid}", [
                'option_uuid' => $item->question->options()->first()->uuid,
            ])
            ->assertStatus(422);
    }

    public function test_la_tentative_expiree_est_close_par_le_serveur(): void
    {
        $attempt = $this->ouvrir();
        $item = $attempt->items()->first();
        $option = $item->question->options()->where('is_correct', true)->first();

        $this->service()->answer($item, $option, 'sure');

        $this->travel($this->epreuve->duration_minutes + 1)->minutes();

        $this->actingAs($this->candidat)
            ->putJson("/api/v1/me/attempts/{$attempt->uuid}/items/{$item->uuid}", [
                'option_uuid' => $option->uuid, 'confidence' => 'sure',
            ])->assertStatus(409);

        $clos = $attempt->fresh();

        $this->assertSame('expired', $clos->status);
        $this->assertNotNull($clos->submitted_at, 'Une tentative expirée est soumise elle aussi.');
        $this->assertSame(1, $clos->correct_count, 'La correction est figée à la clôture, comme partout.');

        /* L’horloge est rendue : un temps figé se paie dans un test ULTÉRIEUR. */
        $this->travelBack();
    }

    public function test_l_examen_blanc_alimente_maitrise_et_calendrier(): void
    {
        /* Règle 5 du lot : même transaction de clôture, mêmes effets. On répond
         * FAUX pour que le calendrier ait quelque chose à planifier. */
        $attempt = $this->ouvrir();

        foreach ($attempt->items as $item) {
            $faux = $item->question->options()->where('is_correct', false)->first();
            $this->service()->answer($item, $faux, 'sure');
        }

        $this->service()->submit($attempt);

        $this->assertGreaterThan(
            0,
            ReviewSchedule::where('user_id', $this->candidat->id)->where('exam_id', $this->epreuve->id)->count(),
            "L'examen blanc doit alimenter le calendrier mémoire comme toute autre série."
        );

        $this->assertDatabaseHas('mastery_scores', [
            'user_id' => $this->candidat->id,
            'exam_id' => $this->epreuve->id,
        ]);
    }

    // ═══════════════════════════════ 3. Les cinq patrons de concurrence ═══

    public function test_rejouer_la_meme_cle_ne_cree_pas_deux_simulations(): void
    {
        $cle = (string) Str::uuid7();

        $a = $this->service()->startSimulation($this->candidat, $this->epreuve, 'fr', $cle, 12);
        $b = $this->service()->startSimulation($this->candidat, $this->epreuve, 'fr', $cle, 12);

        $this->assertSame($a['attempt']->id, $b['attempt']->id);
        $this->assertTrue($a['creee']);
        $this->assertFalse($b['creee'], 'Le second appel ne CRÉE pas : il rend.');
        $this->assertSame(1, Attempt::where('kind', 'simulation')->count());
    }

    public function test_une_cle_rejouee_sur_une_autre_demande_est_refusee(): void
    {
        // L'empreinte porte le total : même clé, autre opération → refus net.
        $cle = (string) Str::uuid7();

        $this->service()->startSimulation($this->candidat, $this->epreuve, 'fr', $cle, 12);

        $this->expectException(IdempotencyKeyReused::class);

        $this->service()->startSimulation($this->candidat, $this->epreuve, 'fr', $cle, 15);
    }

    public function test_une_seule_simulation_ouverte_a_la_fois(): void
    {
        $a = $this->ouvrir();
        $b = $this->ouvrir();

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Attempt::where('kind', 'simulation')->count());
    }

    public function test_l_unicite_est_garantie_par_la_base_pas_par_le_service(): void
    {
        /* Le filet est un index unique partiel : un double clic qui contourne
         * le service doit échouer EN BASE. */
        $this->ouvrir();

        $this->expectException(QueryException::class);

        Attempt::create([
            'user_id' => $this->candidat->id, 'exam_id' => $this->epreuve->id, 'locale' => 'fr',
            'idempotency_key' => (string) Str::uuid7(), 'kind' => 'simulation',
            'status' => 'in_progress', 'started_at' => now(), 'item_count' => 12,
        ]);
    }

    public function test_la_portee_est_globale_et_non_par_epreuve(): void
    {
        /* Différence VOULUE avec le diagnostic : deux échéances dures qui
         * courent en parallèle laisseraient le candidat découvrir une épreuve
         * expirée qu'il n'a jamais passée. */
        $autreEpreuve = Exam::where('code', 'CRMEF-FR-SPEC-2025')->firstOrFail();

        $a = $this->ouvrir();

        $b = $this->service()->startSimulation(
            $this->candidat, $autreEpreuve, 'fr', (string) Str::uuid7(), 12
        )['attempt'];

        $this->assertSame($a->id, $b->id, 'Une seule simulation ouverte, toutes épreuves confondues.');
    }

    public function test_une_simulation_expiree_ne_bloque_pas_la_suivante(): void
    {
        $premiere = $this->ouvrir();

        $this->travel($this->epreuve->duration_minutes + 1)->minutes();

        $seconde = $this->ouvrir();

        $this->assertNotSame($premiere->id, $seconde->id);
        $this->assertSame('expired', $premiere->fresh()->status);

        /* L’horloge est rendue : un temps figé se paie dans un test ULTÉRIEUR. */
        $this->travelBack();
    }

    public function test_l_ouverture_repond_201_puis_200(): void
    {
        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/simulations/{$this->epreuve->code}", ['total' => 12])
            ->assertStatus(201);

        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/simulations/{$this->epreuve->code}", ['total' => 12])
            ->assertStatus(200);
    }

    public function test_la_cle_reutilisee_est_interceptee_sous_son_propre_nom(): void
    {
        /* Interception NOMMÉE : capturée avant le `RuntimeException` dont elle
         * hérite, sinon elle se déguise en « examen blanc indisponible ». */
        $cle = (string) Str::uuid7();

        $this->actingAs($this->candidat)
            ->withHeader('Idempotency-Key', $cle)
            ->postJson("/api/v1/me/simulations/{$this->epreuve->code}", ['total' => 12])
            ->assertStatus(201);

        $this->actingAs($this->candidat)
            ->withHeader('Idempotency-Key', $cle)
            ->postJson("/api/v1/me/simulations/{$this->epreuve->code}", ['total' => 15])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    // ══════════════════════════════════════════════ 4. R06 et le rapport ═══

    public function test_aucun_score_pendant_l_epreuve(): void
    {
        // R06 : aucune correction pendant l'épreuve. La garde est structurelle
        // dans AttemptResource — on vérifie qu'elle s'applique ici aussi.
        $attempt = $this->ouvrir();

        $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$attempt->uuid}")
            ->assertOk()
            ->assertJsonPath('data.correct_count', null);

        $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/simulations/{$attempt->uuid}/report")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ATTEMPT_NOT_SUBMITTED');
    }

    public function test_le_rapport_note_sur_les_poids_officiels(): void
    {
        $attempt = $this->ouvrir(12);

        // Tout juste : la note pondérée doit valoir 100 %.
        foreach ($attempt->items as $item) {
            $juste = $item->question->options()->where('is_correct', true)->first();
            $this->service()->answer($item, $juste, 'sure');
        }

        $this->service()->submit($attempt);

        $corps = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/simulations/{$attempt->uuid}/report")
            ->assertOk()
            ->json('data');

        $this->assertEquals(100.0, $corps['score']['weighted_percent']);
        $this->assertEquals(100.0, $corps['score']['weight_covered'], 'Toute la matrice a été couverte.');
        $this->assertNotEmpty($corps['sections']);

        foreach ($corps['sections'] as $section) {
            // Aucun score sans son volume d'évidence.
            $this->assertArrayHasKey('asked', $section);
            $this->assertGreaterThan(0, $section['asked']);
            $this->assertNotNull($section['weight_percent']);
        }
    }

    public function test_la_note_ponderee_n_est_pas_la_moyenne_brute(): void
    {
        /*
         * LA PREUVE QUE LA PONDÉRATION SERT À QUELQUE CHOSE.
         *
         * On répond juste UNIQUEMENT sur le domaine le plus lourd, faux
         * partout ailleurs. La note pondérée doit alors dépasser la proportion
         * brute de bonnes réponses — sans quoi le « barème réel » ne serait
         * qu'un mot.
         */
        $attempt = $this->ouvrir(12);

        /* `depth = 1` EST INDISPENSABLE : les nœuds PARENTS portent eux aussi un
         * `weight_percent` (SE-PSY vaut 40, somme de ses deux feuilles), mais
         * aucune question ne leur est rattachée. `DiagnosticComposer` filtre
         * `depth > 0` pour cette raison ; un test qui l'oublie choisit un
         * domaine sans item et croit à tort que rien n'est juste. */
        $lourd = CompetencyNode::where('exam_id', $this->epreuve->id)
            ->where('depth', 1)
            ->whereNotNull('weight_percent')->orderByDesc('weight_percent')->first();

        foreach ($attempt->items as $item) {
            $juste = $item->competency_node_id === $lourd->id;
            $option = $item->question->options()->where('is_correct', $juste)->first();
            $this->service()->answer($item, $option, 'sure');
        }

        $this->service()->submit($attempt);

        $corps = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/simulations/{$attempt->uuid}/report")
            ->assertOk()->json('data');

        $brut = $corps['raw']['correct'] / $corps['raw']['asked'] * 100;

        $this->assertGreaterThan(
            $brut,
            $corps['score']['weighted_percent'],
            'Réussir le domaine le plus lourd doit peser plus que sa part en nombre de questions.'
        );
    }

    public function test_le_rapport_ne_predit_rien_et_ne_note_pas_sur_vingt(): void
    {
        $attempt = $this->ouvrir(12);
        $this->service()->submit($attempt);

        $corps = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/simulations/{$attempt->uuid}/report")
            ->assertOk()->json('data');

        // Le barème n'est pas public : le contrat le DIT, il ne le comble pas.
        $this->assertNull($corps['official']['question_count']);
        $this->assertStringContainsString('non précisé', $corps['official']['scoring_note']);
        $this->assertNotEmpty($corps['meta']['not_official_scale']);
        $this->assertNotEmpty($corps['meta']['disclaimer']);

        /*
         * AUCUNE PRÉDICTION — ET LE SCAN PORTE SUR LES DONNÉES, PAS SUR LES
         * PHRASES OÙ LE CONTRAT SE DÉCRIT LUI-MÊME. Cette frontière EST la
         * règle, et les deux premières écritures de ce test s'y sont cassées.
         *
         * `official.admission_threshold_note` cite le descriptif — « Seuil
         * d'admission non précisé » : le mot « admission » y est légitime,
         * c'est une CITATION, pas un verdict appliqué au candidat.
         * `meta.disclaimer` contient « Aucune prédiction […] n'est produite » :
         * une NÉGATION, dont la présence est précisément ce qu'on veut.
         *
         * Interdire ces mots partout reviendrait à interdire au produit de
         * dire ce que la source dit et d'annoncer ce qu'il ne fait pas — soit
         * l'inverse du but. Ce qui doit être exempt de prédiction, c'est la
         * MESURE : score, agrégats, sections, ordonnance.
         */
        $donnees = [
            'score' => $corps['score'],
            'raw' => $corps['raw'],
            'sections' => $corps['sections'],
            'plan' => $corps['plan'],
        ];

        $plat = json_encode($donnees, JSON_UNESCAPED_UNICODE);

        foreach (['admis', 'probabilit', 'chance', 'rang ', 'prédiction', 'pronostic', 'seriez'] as $interdit) {
            $this->assertStringNotContainsStringIgnoringCase(
                $interdit, $plat, "Le rapport ne doit rien prédire — « {$interdit} » y figure."
            );
        }

        /* Et les deux textes exclus du scan sont VÉRIFIÉS PRÉSENTS : sans cela,
         * on « corrigerait » un jour ce test en supprimant la citation ou le
         * démenti, et il resterait vert sur un rapport devenu muet. */
        $this->assertNotEmpty($corps['official']['admission_threshold_note']);
        $this->assertStringContainsString('Aucune prédiction', $corps['meta']['disclaimer']);
    }

    public function test_les_citations_officielles_suivent_la_langue_du_candidat(): void
    {
        /*
         * DET-54. Ces deux citations n'existaient qu'en français : un candidat
         * arabophone lisait « Barème détaillé non précisé par le descriptif
         * officiel » sur une page en arabe. `dir="auto"` rendait le mélange
         * LISIBLE, ce qui masquait le défaut sans le corriger.
         */
        $arabophone = $this->compte('arabophone@naja7i.ma');
        $arabophone->update(['locale' => 'ar']);

        $attempt = $this->service()->startSimulation(
            $arabophone, $this->epreuve, 'fr', (string) Str::uuid7(), 12
        )['attempt'];

        $this->service()->submit($attempt);

        $this->flushSession();

        $corps = $this->actingAs($arabophone)
            ->getJson("/api/v1/me/simulations/{$attempt->uuid}/report")
            ->assertOk()->json('data.official');

        $this->assertSame(
            'سلّم التنقيط المفصّل غير محدَّد في الوصف الرسمي.',
            $corps['scoring_note'],
        );
        $this->assertSame(
            'عتبة القبول غير محدَّدة في الوصف الرسمي.',
            $corps['admission_threshold_note'],
        );
    }

    public function test_le_francais_reste_le_repli_quand_l_arabe_manque(): void
    {
        /*
         * Ajouter la colonne n'oblige pas à la remplir : les blueprints restent
         * vides tant qu'aucune source ne les établit. Une citation vraie mais
         * pas encore traduite vaut mieux que rien — c'est ce que faisait déjà
         * le produit, à ceci près qu'il n'avait alors aucune autre issue.
         */
        $this->epreuve->currentBlueprint->update([
            'official_scoring_note_ar' => null,
            'official_admission_threshold_note_ar' => null,
        ]);

        $arabophone = $this->compte('sans-traduction@naja7i.ma');
        $arabophone->update(['locale' => 'ar']);

        $attempt = $this->service()->startSimulation(
            $arabophone, $this->epreuve, 'fr', (string) Str::uuid7(), 12
        )['attempt'];

        $this->service()->submit($attempt);
        $this->flushSession();

        $corps = $this->actingAs($arabophone)
            ->getJson("/api/v1/me/simulations/{$attempt->uuid}/report")
            ->assertOk()->json('data.official');

        $this->assertStringContainsString('Barème détaillé', $corps['scoring_note']);
    }

    public function test_le_rapport_renvoie_vers_l_ordonnance(): void
    {
        $attempt = $this->ouvrir(12);

        foreach ($attempt->items as $item) {
            $faux = $item->question->options()->where('is_correct', false)->first();
            $this->service()->answer($item, $faux, 'sure');
        }

        $this->service()->submit($attempt);

        $corps = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/simulations/{$attempt->uuid}/report")
            ->assertOk()->json('data');

        $this->assertNotEmpty($corps['plan'], "Un rapport sans « et maintenant ? » n'apprend rien.");
    }

    public function test_le_rapport_n_expose_aucune_correction_par_item(): void
    {
        // Liste blanche stricte : la correction a sa route, son quota et son
        // mur payant. Elle n'entre pas ici par la bande.
        $attempt = $this->ouvrir(12);
        $this->service()->submit($attempt);

        $brut = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/simulations/{$attempt->uuid}/report")
            ->assertOk()->getContent();

        foreach (['RATIONALE_SECRETE', 'JUSTIFICATION_SECRETE', 'is_correct', 'cause'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $brut);
        }
    }

    public function test_le_rapport_d_un_autre_candidat_est_introuvable(): void
    {
        $attempt = $this->ouvrir(12);
        $this->service()->submit($attempt);

        $autre = $this->compte('intrus@naja7i.ma');

        $this->flushSession();

        $this->actingAs($autre)
            ->getJson("/api/v1/me/simulations/{$attempt->uuid}/report")
            ->assertStatus(404);   // 404, jamais 403.
    }

    public function test_le_rapport_clot_une_epreuve_dont_le_temps_est_ecoule(): void
    {
        /* Le candidat ferme l'onglet et revient une heure plus tard : il ne
         * peut plus soumettre, et le serveur ne peut pas lui répondre « pas
         * encore terminée ». Il clôt, puis rend le rapport. */
        $attempt = $this->ouvrir(12);

        $this->travel($this->epreuve->duration_minutes + 1)->minutes();

        $corps = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/simulations/{$attempt->uuid}/report")
            ->assertOk()->json('data');

        $this->assertSame('expired', $corps['status']);
        $this->assertSame('expired', $attempt->fresh()->status);

        /* L’horloge est rendue : un temps figé se paie dans un test ULTÉRIEUR. */
        $this->travelBack();
    }

    public function test_une_serie_sans_reponse_ne_rend_pas_zero_sur_l_absence(): void
    {
        /* « 0 % » sur une section jamais interrogée serait une affirmation
         * fausse. On ne mesure ici que ce qui a été posé. */
        $attempt = $this->ouvrir(12);
        $this->service()->submit($attempt);

        $corps = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/simulations/{$attempt->uuid}/report")
            ->assertOk()->json('data');

        $this->assertEquals(0.0, $corps['score']['weighted_percent'], 'Tout faux se dit bien 0.');
        $this->assertGreaterThan(0, $corps['score']['weight_covered']);
        $this->assertSame(0, $corps['raw']['answered']);
    }
}
