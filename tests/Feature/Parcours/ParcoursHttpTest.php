<?php

namespace Tests\Feature\Parcours;

use App\Contracts\AccessGrant;
use App\Models\AccessGrantRecord;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\Crmef2025Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParcoursHttpTest extends TestCase
{
    use RefreshDatabase;

    private Exam $epreuve;

    private User $candidat;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->seed(CatalogueSeeder::class);
        $this->seed(Crmef2025Seeder::class);

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->candidat = $this->candidat('candidat@naja7i.ma');

        $this->peuplerBanque(4);
    }

    /**
     * `email_verified_at` n'est pas dans `$fillable`, et ne doit pas y entrer :
     * un champ qui ouvre l'accès au parcours ne s'assigne pas en masse — même
     * raison que pour `tenant_id`, que le test d'architecture protège déjà.
     * Le passer à `User::create()` était donc sans effet, et le drapeau
     * `$verifie` restait inerte dans les deux sens.
     *
     * On passe par l'API du contrat `MustVerifyEmail`, que le modèle implémente.
     */
    private function candidat(string $email, bool $verifie = true): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);

        if ($verifie) {
            $user->markEmailAsVerified();
        }

        $user->grantCandidateRole();

        return $user;
    }

    private function peuplerBanque(int $parSousDomaine): void
    {
        $valideur = $this->candidat('valideur@naja7i.ma');
        $source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();

        foreach (CompetencyNode::where('exam_id', $this->epreuve->id)->where('depth', 1)->get() as $noeud) {
            $remediation = Remediation::create([
                'competency_node_id' => $noeud->id, 'locale' => 'fr',
                'title' => "Remédiation {$noeud->code}", 'content' => 'Contenu.',
                'estimated_minutes' => 8, 'status' => 'published',
            ]);

            for ($i = 1; $i <= $parSousDomaine; $i++) {
                /* Champs de transition non assignables (REVUE PAS-5 BLOC-1) :
                 * la question naît brouillon et gagne sa publication par le
                 * service, seul chemin ouvert. */
                $question = Question::create([
                    'exam_id' => $this->epreuve->id, 'competency_node_id' => $noeud->id,
                    'locale' => 'fr', 'sibling_group' => (string) Str::uuid7(),
                    'stem' => "Énoncé {$i} — {$noeud->code}",
                    'explanation' => 'JUSTIFICATION_GENERALE_SECRETE',
                    'remediation_id' => $remediation->id,
                ]);

                foreach ([
                    ['Option A', false, 'RATIONALE_SECRETE_A', 'confusion_notions'],
                    ['Option B', true,  'RATIONALE_SECRETE_B', null],
                    ['Option C', false, 'RATIONALE_SECRETE_C', 'lecture_enonce'],
                    ['Option D', false, 'RATIONALE_SECRETE_D', 'connaissance_absente'],
                ] as $p => [$c, $juste, $justif, $cause]) {
                    QuestionOption::create([
                        'question_id' => $question->id, 'position' => $p + 1,
                        'content' => $c, 'is_correct' => $juste, 'rationale' => $justif, 'cause' => $cause,
                    ]);
                }

                $question->contentSources()->attach($source->id, ['verification' => 'verified']);

                $transitions = app(QuestionTransitionService::class);
                $transitions->submitForReview($question);
                $transitions->markReviewed($question, $valideur);
                $transitions->validate($question, $valideur);
                $transitions->publish($question, forDiagnostic: true);
            }
        }
    }

    /**
     * Bascule vers un autre candidat en repartant d'une session vierge.
     *
     * `AuthenticateSession`, inclus dans la pile stateful de Sanctum, tue la
     * session dès que l'utilisateur authentifié ne correspond plus à celui dont
     * elle porte l'empreinte — c'est ce qui met fin aux sessions ouvertes à un
     * changement d'identifiants, et il n'est pas question de l'affaiblir. En
     * production, deux candidats ne partagent jamais une session ; seul un test
     * qui enchaîne deux `actingAs` sur la même rencontre ce chemin, et la
     * première requête après la bascule y est rejetée en 401.
     *
     * Repartir d'une session vierge, c'est exactement ce que fait un second
     * navigateur.
     */
    private function agirComme(User $user): self
    {
        $this->flushSession();

        return $this->actingAs($user);
    }

    private function ouvrirDiagnostic(): array
    {
        $reponse = $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/diagnostics/{$this->epreuve->code}")
            ->assertCreated();

        return $reponse->json('data');
    }

    // --- LE test qui compte : aucune fuite pendant la tentative -------------

    public function test_pendant_la_tentative_rien_ne_revele_la_bonne_reponse(): void
    {
        $attempt = $this->ouvrirDiagnostic();

        $corps = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$attempt['uuid']}")
            ->assertOk()
            ->content();

        foreach ([
            'RATIONALE_SECRETE', 'JUSTIFICATION_GENERALE_SECRETE',
            'is_correct', 'rationale', 'cause', 'confusion_notions',
        ] as $fuite) {
            $this->assertStringNotContainsString(
                $fuite, $corps,
                "« {$fuite} » ne doit jamais apparaître avant la soumission."
            );
        }
    }

    public function test_repondre_ne_renvoie_aucun_verdict(): void
    {
        $attempt = $this->ouvrirDiagnostic();
        $item = $attempt['items'][0];
        $option = $item['question']['options'][0];

        $corps = $this->actingAs($this->candidat)
            ->putJson("/api/v1/me/attempts/{$attempt['uuid']}/items/{$item['item_uuid']}", [
                'option_uuid' => $option['uuid'], 'confidence' => 'sure',
            ])
            ->assertOk()
            ->content();

        $this->assertStringNotContainsString('is_correct', $corps);
        $this->assertStringNotContainsString('rationale', $corps);
    }

    public function test_la_correction_est_refusee_avant_soumission(): void
    {
        $attempt = $this->ouvrirDiagnostic();

        $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$attempt['uuid']}/correction")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ATTEMPT_NOT_SUBMITTED');
    }

    // --- Parcours complet ----------------------------------------------------

    public function test_le_cycle_complet_fonctionne(): void
    {
        $attempt = $this->ouvrirDiagnostic();

        $this->assertSame(10, $attempt['item_count']);
        $this->assertSame('in_progress', $attempt['status']);

        foreach ($attempt['items'] as $item) {
            $this->actingAs($this->candidat)
                ->putJson("/api/v1/me/attempts/{$attempt['uuid']}/items/{$item['item_uuid']}", [
                    'option_uuid' => $item['question']['options'][1]['uuid'],  // la bonne
                    'confidence' => 'sure',
                ])->assertOk();
        }

        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/attempts/{$attempt['uuid']}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.correct_count', 10);

        // La maîtrise est recalculée dans la foulée.
        $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/mastery/{$this->epreuve->code}")
            ->assertOk()
            ->assertJsonStructure(['data' => [['node_code', 'score', 'evidence', 'answered_count']]]);
    }

    public function test_l_ouverture_est_idempotente_par_entete(): void
    {
        $cle = (string) Str::uuid7();

        $a = $this->actingAs($this->candidat)->withHeaders(['Idempotency-Key' => $cle])
            ->postJson("/api/v1/me/diagnostics/{$this->epreuve->code}")->json('data.uuid');

        $b = $this->actingAs($this->candidat)->withHeaders(['Idempotency-Key' => $cle])
            ->postJson("/api/v1/me/diagnostics/{$this->epreuve->code}")->json('data.uuid');

        $this->assertSame($a, $b);
    }

    // --- Quota de causes (fiche F03) ----------------------------------------

    public function test_le_compte_gratuit_voit_deux_causes_puis_le_verrou(): void
    {
        $attempt = $this->ouvrirDiagnostic();

        foreach ($attempt['items'] as $item) {
            $this->actingAs($this->candidat)
                ->putJson("/api/v1/me/attempts/{$attempt['uuid']}/items/{$item['item_uuid']}", [
                    'option_uuid' => $item['question']['options'][0]['uuid'],  // fausse
                    'confidence' => 'hesitant',
                ]);
        }

        $this->actingAs($this->candidat)->postJson("/api/v1/me/attempts/{$attempt['uuid']}/submit");

        $correction = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$attempt['uuid']}/correction")
            ->assertOk();

        $verrouilles = collect($correction->json('data'))->where('cause_locked', true);

        $this->assertSame(2, $correction->json('meta.cause_quota.revealed'));
        $this->assertCount(8, $verrouilles, 'Huit corrections sur dix doivent rester verrouillées.');
        $this->assertFalse($correction->json('meta.cause_quota.unlimited'));
    }

    public function test_la_justification_reste_visible_meme_quand_la_cause_est_verrouillee(): void
    {
        $attempt = $this->ouvrirDiagnostic();

        foreach ($attempt['items'] as $item) {
            $this->actingAs($this->candidat)
                ->putJson("/api/v1/me/attempts/{$attempt['uuid']}/items/{$item['item_uuid']}", [
                    'option_uuid' => $item['question']['options'][0]['uuid'], 'confidence' => 'guess',
                ]);
        }
        $this->actingAs($this->candidat)->postJson("/api/v1/me/attempts/{$attempt['uuid']}/submit");

        $verrouillee = collect(
            $this->actingAs($this->candidat)
                ->getJson("/api/v1/me/attempts/{$attempt['uuid']}/correction")->json('data')
        )->firstWhere('cause_locked', true);

        $this->assertNotNull($verrouillee);
        $this->assertNull($verrouillee['options'][0]['cause'], 'La cause est masquée.');
        $this->assertStringContainsString('RATIONALE_SECRETE', $verrouillee['options'][0]['rationale'],
            'Mais la justification reste : sinon le compte gratuit devient un QCM ordinaire.');
    }

    public function test_un_abonne_voit_toutes_les_causes(): void
    {
        AccessGrantRecord::create([
            'user_id' => $this->candidat->id,
            'capability' => AccessGrant::CAUSE_REVEAL,
            'starts_at' => now()->subDay(), 'origin' => 'purchase',
        ]);

        $attempt = $this->ouvrirDiagnostic();

        foreach ($attempt['items'] as $item) {
            $this->actingAs($this->candidat)
                ->putJson("/api/v1/me/attempts/{$attempt['uuid']}/items/{$item['item_uuid']}", [
                    'option_uuid' => $item['question']['options'][0]['uuid'], 'confidence' => 'hesitant',
                ]);
        }
        $this->actingAs($this->candidat)->postJson("/api/v1/me/attempts/{$attempt['uuid']}/submit");

        $correction = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$attempt['uuid']}/correction")->assertOk();

        $this->assertTrue($correction->json('meta.cause_quota.unlimited'));
        $this->assertCount(0, collect($correction->json('data'))->where('cause_locked', true));
    }

    public function test_un_octroi_expire_ne_donne_plus_acces(): void
    {
        AccessGrantRecord::create([
            'user_id' => $this->candidat->id,
            'capability' => AccessGrant::CAUSE_REVEAL,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->subDay(),
            'origin' => 'purchase',
        ]);

        $this->assertFalse(app(AccessGrant::class)->allows($this->candidat, AccessGrant::CAUSE_REVEAL));
    }

    // --- Cloisonnement entre candidats --------------------------------------

    public function test_la_tentative_d_un_autre_candidat_est_introuvable(): void
    {
        $attempt = $this->ouvrirDiagnostic();
        $autre = $this->candidat('intrus@naja7i.ma');

        $this->agirComme($autre)
            ->getJson("/api/v1/me/attempts/{$attempt['uuid']}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_repondre_sur_la_tentative_d_un_autre_est_introuvable(): void
    {
        $attempt = $this->ouvrirDiagnostic();
        $item = $attempt['items'][0];
        $autre = $this->candidat('intrus2@naja7i.ma');

        $this->agirComme($autre)
            ->putJson("/api/v1/me/attempts/{$attempt['uuid']}/items/{$item['item_uuid']}", [
                'option_uuid' => $item['question']['options'][0]['uuid'], 'confidence' => 'sure',
            ])->assertStatus(404);
    }

    public function test_le_parcours_exige_une_session(): void
    {
        $this->postJson("/api/v1/me/diagnostics/{$this->epreuve->code}")->assertStatus(401);
        $this->getJson("/api/v1/me/mastery/{$this->epreuve->code}")->assertStatus(401);
    }

    public function test_un_email_non_verifie_bloque_le_parcours(): void
    {
        $nonVerifie = $this->candidat('nonverifie@naja7i.ma', verifie: false);

        $this->actingAs($nonVerifie)
            ->postJson("/api/v1/me/diagnostics/{$this->epreuve->code}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'AUTH_EMAIL_NOT_VERIFIED');
    }

    // --- Banque insuffisante -------------------------------------------------

    public function test_une_epreuve_sans_banque_refuse_d_ouvrir_un_diagnostic(): void
    {
        $didactique = Exam::where('code', 'CRMEF-FR-DID-2025')->firstOrFail();

        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/diagnostics/{$didactique->code}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DIAGNOSTIC_NOT_AVAILABLE');
    }

    // --- Démonstration publique (fiche F03) ----------------------------------

    public function test_la_demonstration_est_publique_et_marquee_comme_exemple(): void
    {
        $this->getJson('/api/v1/demonstration/correction')
            ->assertOk()
            ->assertJsonPath('data.is_example', true)
            ->assertJsonStructure([
                'data' => ['question' => ['stem', 'explanation'], 'options', 'competency', 'exam'],
                'meta' => ['notice'],
            ]);
    }

    public function test_la_demonstration_montre_les_quatre_justifications_et_les_causes(): void
    {
        $options = $this->getJson('/api/v1/demonstration/correction')->json('data.options');

        $this->assertCount(4, $options);
        $this->assertCount(3, collect($options)->whereNotNull('cause'),
            'Les trois distracteurs portent leur cause : c\'est tout l\'intérêt de la démonstration.');
        $this->assertEmpty(collect($options)->where('rationale', null));
    }

    // --- Ordonnance -----------------------------------------------------------

    public function test_l_ordonnance_rappelle_qu_aucune_prediction_n_est_produite(): void
    {
        $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/plan/{$this->epreuve->code}")
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['exam_code', 'disclaimer']]);
    }

    public function test_aucune_cle_interne_dans_les_reponses_du_parcours(): void
    {
        $attempt = $this->ouvrirDiagnostic();

        foreach ([
            "/api/v1/me/attempts/{$attempt['uuid']}",
            "/api/v1/me/mastery/{$this->epreuve->code}",
            "/api/v1/me/plan/{$this->epreuve->code}",
            '/api/v1/demonstration/correction',
        ] as $url) {
            $corps = $this->actingAs($this->candidat)->getJson($url)->content();

            foreach (['"id":', '"tenant_id"', '"user_id"', '"question_id"', '"exam_id"'] as $interdit) {
                $this->assertStringNotContainsString($interdit, $corps, "{$interdit} exposé sur {$url}");
            }
        }
    }
}
