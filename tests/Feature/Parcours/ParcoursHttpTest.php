<?php

namespace Tests\Feature\Parcours;

use App\Contracts\AccessGrant;
use App\Http\Controllers\Api\V1\ParcoursController;
use App\Models\AccessGrantRecord;
use App\Models\Attempt;
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

        $items = collect($correction->json('data'));

        /*
         * DEUX UNITÉS ACHÈTENT DEUX COUPLES, plus deux réponses.
         *
         * Le décompte était auparavant « huit verrouillées sur dix » : une
         * unité par RÉPONSE. Depuis le PAS-26, l'unité porte sur le couple
         * (compétence, cause) — F05 l'a imposé, la question miroir portant par
         * construction une cause qu'on vient de payer, et la faire repayer
         * reviendrait à vendre deux fois le même diagnostic.
         *
         * Le test exprime donc la RÈGLE et non un nombre : deux unités
         * consommées, deux couples ouverts, le reste fermé. Ici toutes les
         * erreurs portent la même cause, les couples se distinguent donc par
         * leur compétence.
         */
        $this->assertSame(2, $correction->json('meta.cause_quota.revealed'));
        $this->assertFalse($correction->json('meta.cause_quota.unlimited'));

        $ouvertes = $items->where('cause_locked', false)->pluck('competency.code')->unique();
        $fermees = $items->where('cause_locked', true);

        $this->assertCount(2, $ouvertes, 'Deux unités ouvrent exactement deux couples.');
        $this->assertTrue($fermees->isNotEmpty(), 'Le mur tient sur les couples non payés.');
        $this->assertEmpty(
            $fermees->pluck('competency.code')->unique()->intersect($ouvertes),
            'Aucune compétence ne peut être à la fois ouverte et fermée : le couple est payé ou il ne l\'est pas.'
        );
    }

    /*
     * ══════════════════════════════════════════════════════════════════════
     * BLOC-1 DE L'AUDIT TOURNÉE 3 — F03 ÉTAIT CONTOURNABLE SANS RÉPONDRE.
     *
     * `$visible = ! $fausse || reveal(...)` : pour un item SANS RÉPONSE,
     * `is_correct === false` vaut faux, donc `$fausse` est faux, donc
     * `$visible` passait à vrai SANS toucher au quota. Et `CorrectionResource`
     * sérialisait alors la cause de TOUTES les options.
     *
     * Un compte gratuit ouvrait une série, ne répondait à rien, soumettait, et
     * lisait les trente causes. Le plafond protégeait le compteur, pas la
     * charge utile.
     *
     * Ces trois tests étaient absents — l'ancien mesurait `cause_locked` et le
     * compteur, jamais les VALEURS `cause` réellement rendues.
     * ══════════════════════════════════════════════════════════════════════
     */

    /**
     * L'uuid de la bonne option d'un item, LU EN BASE.
     *
     * La passation ne sert pas `is_correct` — c'est R06, et cette liste blanche
     * est précisément ce qu'on ne veut pas assouplir pour arranger un test. Le
     * test descend donc à la base, comme le ferait un correcteur.
     */
    private function bonneOption(array $item): string
    {
        return QuestionOption::whereHas(
            'question',
            fn ($q) => $q->where('uuid', $item['question']['uuid'])
        )->where('is_correct', true)->value('uuid');
    }

    /*
     * POURQUOI IL N'Y A PAS DE TEST SUR LA LIGNE DU CONTRÔLEUR.
     *
     * Le correctif du BLOC-1 tient en deux endroits : `$visible = $fausse &&
     * reveal(...)` dans `ParcoursController`, et la cause du seul distracteur
     * choisi dans `CorrectionResource`. La mutation qui rétablit `! $fausse ||`
     * dans le contrôleur NE FAIT ROUGIR AUCUN TEST, et c'est vérifié, pas
     * supposé.
     *
     * La raison est que trois gardes indépendantes rendent l'état inatteignable :
     *
     *   1. `QuestionAuthoringService` retire une cause posée sur la bonne
     *      réponse — donc la bonne option n'en porte jamais à l'écriture ;
     *   2. un déclencheur de base GÈLE les options d'une question publiée
     *      (ADR-0015 §5) — donc on ne peut pas en poser une après coup ;
     *   3. la ressource ne sert que la cause de l'option CHOISIE.
     *
     * Écrire quand même un test aurait demandé de désactiver le déclencheur
     * dans la transaction de test — ce que PostgreSQL refuse d'ailleurs quand
     * des événements sont en attente. Fabriquer un état que le produit interdit
     * pour prouver une ligne, c'est tester le démontage, pas la règle.
     *
     * La ligne du contrôleur reste : elle énonce la règle LÀ OÙ ELLE SE DÉCIDE
     * — une cause ne sort jamais sans acquisition — et elle tiendra le jour où
     * l'une des trois gardes bougera. Ce commentaire existe pour qu'on ne la
     * supprime pas un jour au motif qu'« aucun test ne la couvre ».
     */

    /** Toutes les valeurs `cause` non nulles servies par une correction. */
    private function causesServies(array $donnees): array
    {
        return collect($donnees)
            ->flatMap(fn (array $ligne) => collect($ligne['options'])->pluck('cause'))
            ->filter()
            ->values()
            ->all();
    }

    public function test_une_serie_soumise_sans_aucune_reponse_ne_livre_aucune_cause(): void
    {
        $attempt = $this->ouvrirDiagnostic();

        // On ne répond à RIEN. C'est tout le scénario.
        $this->actingAs($this->candidat)->postJson("/api/v1/me/attempts/{$attempt['uuid']}/submit");

        $correction = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$attempt['uuid']}/correction")
            ->assertOk();

        $this->assertSame(
            [],
            $this->causesServies($correction->json('data')),
            'Un item sans réponse n\'a aucune erreur à diagnostiquer : il ne peut pas livrer de cause.'
        );

        $this->assertSame(0, $correction->json('meta.cause_quota.revealed'));
    }

    public function test_une_bonne_reponse_ne_livre_aucune_cause(): void
    {
        $attempt = $this->ouvrirDiagnostic();

        foreach ($attempt['items'] as $item) {
            $juste = collect($item['question']['options'])->firstWhere('uuid', $this->bonneOption($item));

            $this->actingAs($this->candidat)
                ->putJson("/api/v1/me/attempts/{$attempt['uuid']}/items/{$item['item_uuid']}", [
                    'option_uuid' => $juste['uuid'],
                    'confidence' => 'sure',
                ]);
        }

        $this->actingAs($this->candidat)->postJson("/api/v1/me/attempts/{$attempt['uuid']}/submit");

        $correction = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$attempt['uuid']}/correction")
            ->assertOk();

        $this->assertSame([], $this->causesServies($correction->json('data')));
        $this->assertSame(0, $correction->json('meta.cause_quota.revealed'));
    }

    public function test_une_reponse_fausse_ne_livre_que_la_cause_du_distracteur_choisi(): void
    {
        /*
         * F03 : « Lit la cause associée au DISTRACTEUR CHOISI ». Rendre aussi
         * celles des distracteurs non choisis livre un diagnostic que le
         * candidat n'a pas demandé — et trois causes pour une unité de quota.
         */
        $attempt = $this->ouvrirDiagnostic();
        $premier = $attempt['items'][0];

        $choisie = collect($premier['question']['options'])
            ->first(fn (array $o) => $o['uuid'] !== $this->bonneOption($premier));

        $this->actingAs($this->candidat)
            ->putJson("/api/v1/me/attempts/{$attempt['uuid']}/items/{$premier['item_uuid']}", [
                'option_uuid' => $choisie['uuid'],
                'confidence' => 'sure',
            ]);

        $this->actingAs($this->candidat)->postJson("/api/v1/me/attempts/{$attempt['uuid']}/submit");

        $correction = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$attempt['uuid']}/correction")
            ->assertOk();

        $this->assertCount(
            1,
            $this->causesServies($correction->json('data')),
            'Une réponse fausse ouvre UNE cause : celle du distracteur choisi.'
        );

        $ligne = collect($correction->json('data'))
            ->firstWhere('item_uuid', $premier['item_uuid']);

        $portee = collect($ligne['options'])->firstWhere('uuid', $choisie['uuid']);

        $this->assertNotNull($portee['cause'], 'La cause servie est celle de l\'option choisie.');
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

    // --- Index des tentatives ------------------------------------------------

    public function test_l_index_rend_les_tentatives_du_candidat_la_plus_recente_d_abord(): void
    {
        $premier = $this->ouvrirDiagnostic();
        $this->actingAs($this->candidat)->postJson("/api/v1/me/attempts/{$premier['uuid']}/submit")->assertOk();

        $second = $this->ouvrirDiagnostic();

        $reponse = $this->actingAs($this->candidat)->getJson('/api/v1/me/attempts');

        $reponse->assertOk();
        $this->assertSame(2, $reponse->json('meta.total'));
        $this->assertSame($second['uuid'], $reponse->json('data.0.uuid'), 'La plus récente d\'abord.');
        $this->assertSame(
            $this->epreuve->code, $reponse->json('data.0.exam.code'),
            'L\'épreuve est chargée : c\'est ce qui permet de la déduire sans trace locale.'
        );
    }

    public function test_l_index_ne_montre_jamais_les_tentatives_d_un_autre_candidat(): void
    {
        $this->ouvrirDiagnostic();

        $autre = $this->candidat('autre@naja7i.ma');

        // Session vierge : c'est ce que fait un second navigateur (voir agirComme).
        $reponse = $this->agirComme($autre)->getJson('/api/v1/me/attempts');

        $reponse->assertOk();
        $this->assertSame([], $reponse->json('data'));
        $this->assertSame(0, $reponse->json('meta.total'));
    }

    public function test_le_filtre_status_rend_la_tentative_ouverte_et_elle_seule(): void
    {
        $close = $this->ouvrirDiagnostic();
        $this->actingAs($this->candidat)->postJson("/api/v1/me/attempts/{$close['uuid']}/submit")->assertOk();

        $ouverte = $this->ouvrirDiagnostic();

        $reponse = $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/attempts?status=in_progress');

        $reponse->assertOk();
        $this->assertCount(1, $reponse->json('data'));
        $this->assertSame($ouverte['uuid'], $reponse->json('data.0.uuid'));
    }

    /**
     * Éprouvé sur les OCTETS rendus, pas par lecture du code.
     *
     * `AttemptResource` expose les items par `whenLoaded` : il suffit de ne pas
     * les charger. Mais « il suffit de » est exactement ce qu'un chargement
     * ajouté ailleurs — un `with()` de confort, un `load()` dans un helper —
     * viendrait défaire sans que personne ne le remarque.
     */
    public function test_l_index_ne_porte_aucun_enonce_ni_aucune_option(): void
    {
        $this->ouvrirDiagnostic();

        $corps = $this->actingAs($this->candidat)->getJson('/api/v1/me/attempts')->content();

        $this->assertStringNotContainsString('"items"', $corps, 'Une liste n\'a pas à porter les items.');
        $this->assertStringNotContainsString('Énoncé 1', $corps);
        $this->assertStringNotContainsString('"stem"', $corps);
        $this->assertStringNotContainsString('"options"', $corps);
        $this->assertStringNotContainsString('JUSTIFICATION_GENERALE_SECRETE', $corps);
        $this->assertStringNotContainsString('RATIONALE_SECRETE', $corps);
    }

    public function test_la_borne_de_l_index_est_annoncee_et_non_silencieuse(): void
    {
        $plafond = ParcoursController::PLAFOND_INDEX;

        /* Une tentative de plus que le plafond, créées directement : la route
         * d'ouverture refuserait le second diagnostic ouvert, et ce test porte
         * sur la BORNE, pas sur les règles d'ouverture. */
        for ($i = 0; $i <= $plafond; $i++) {
            Attempt::create([
                'user_id' => $this->candidat->id, 'exam_id' => $this->epreuve->id,
                'locale' => 'fr', 'idempotency_key' => (string) Str::uuid7(),
                'kind' => 'training', 'status' => 'submitted',
                'started_at' => now()->subMinutes($i), 'submitted_at' => now(),
                'item_count' => 5, 'correct_count' => 3,
            ]);
        }

        $reponse = $this->actingAs($this->candidat)->getJson('/api/v1/me/attempts');

        $reponse->assertOk();
        $this->assertCount($plafond, $reponse->json('data'));
        $this->assertSame($plafond + 1, $reponse->json('meta.total'));
        $this->assertSame($plafond, $reponse->json('meta.served'));
        $this->assertSame(
            1, $reponse->json('meta.pending'),
            'Ce qui n\'est pas servi est compté et dit, jamais tronqué en silence.'
        );
        $this->assertSame($plafond, $reponse->json('meta.cap'));
    }

    public function test_un_filtre_inconnu_est_refuse_plutot_que_silencieusement_ignore(): void
    {
        $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/attempts?status=inexistant')
            ->assertStatus(422);
    }

    /**
     * Le filtre par épreuve ne dit JAMAIS ce qui existe.
     *
     * Valider l'existence ferait répondre 422 à un code inconnu et 200 à un
     * code réel hors portée : la différence entre les deux réponses
     * renseignerait sur le catalogue. Les deux répondent 200, liste vide.
     */
    public function test_exam_code_ne_dit_pas_ce_qui_existe(): void
    {
        $this->ouvrirDiagnostic();

        foreach (['CODE-QUI-N-EXISTE-PAS', 'CRMEF-PRIMAIRE-2025'] as $code) {
            $reponse = $this->actingAs($this->candidat)
                ->getJson("/api/v1/me/attempts?exam_code={$code}");

            $reponse->assertOk();
            $this->assertSame([], $reponse->json('data'), "Réponse distincte pour {$code}.");
            $this->assertSame(0, $reponse->json('meta.total'));
        }

        // Et le code réel de l'épreuve suivie rend bien la tentative.
        $this->assertSame(
            1,
            $this->actingAs($this->candidat)
                ->getJson("/api/v1/me/attempts?exam_code={$this->epreuve->code}")
                ->json('meta.total')
        );
    }

    // --- correct_count n'est pas un oracle de correction ----------------------

    /**
     * L'oracle de correction n'existe pas, et ce test le tient À LA SOURCE.
     *
     * L'inquiétude était juste dans sa forme : un compteur de bonnes réponses
     * servi pendant la tentative se lirait une question à la fois — répondre,
     * rappeler la liste, regarder s'il a monté — et livrerait la correction par
     * une porte qui n'était pas censée l'ouvrir.
     *
     * Mais `correct_count` n'est ÉCRIT qu'à la soumission
     * (`AttemptService::submit`) : il vaut nul en base pendant toute la
     * tentative, et aucun chemin ne le maintient au fil des réponses. Vérifié
     * par mutation — retirer la garde de `AttemptResource` ne changeait rien,
     * la valeur étant déjà nulle.
     *
     * D'où la première assertion, qui est la vraie : LA COLONNE reste nulle
     * après dix bonnes réponses. C'est elle qui rougirait le jour où quelqu'un
     * rendrait ce compteur vivant « pour éviter un calcul à la soumission ».
     * La garde de la ressource est la seconde ligne, pas la première.
     */
    public function test_correct_count_reste_nul_tant_que_rien_n_est_soumis(): void
    {
        $attempt = $this->ouvrirDiagnostic();

        foreach ($attempt['items'] as $item) {
            // La bonne réponse est connue du montage : « Option B ».
            $bonne = collect($item['question']['options'])->firstWhere('content', 'Option B');

            $this->actingAs($this->candidat)->putJson(
                "/api/v1/me/attempts/{$attempt['uuid']}/items/{$item['item_uuid']}",
                ['option_uuid' => $bonne['uuid'], 'confidence' => 'sure']
            )->assertOk();

            $this->assertNull(
                Attempt::where('uuid', $attempt['uuid'])->value('correct_count'),
                'La colonne est maintenue au fil des réponses : le compteur est devenu un oracle.'
            );

            foreach ([
                "/api/v1/me/attempts/{$attempt['uuid']}",
                '/api/v1/me/attempts',
            ] as $url) {
                $charge = $this->actingAs($this->candidat)->getJson($url)->json();
                $tentative = $charge['data'][0] ?? $charge['data'];

                $this->assertArrayHasKey(
                    'correct_count', $tentative,
                    'La clé est TOUJOURS présente : un client n\'a pas à distinguer '
                    .'« pas encore » de « champ inconnu ».'
                );
                $this->assertNull(
                    $tentative['correct_count'],
                    "Le compteur de bonnes réponses a fuité sur {$url} avant la soumission."
                );
            }
        }

        // Après soumission, il devient licite — et exact.
        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/attempts/{$attempt['uuid']}/submit")
            ->assertOk()
            ->assertJsonPath('data.correct_count', 10);
    }

    // --- Dernière activité ----------------------------------------------------

    public function test_la_tentative_travaillee_en_dernier_sort_en_tete(): void
    {
        // A, ouverte en premier et laissée en cours.
        $a = $this->ouvrirDiagnostic();

        /* B, ouverte APRÈS A et close aussitôt. Créée directement : deux
         * diagnostics ouverts sur la même épreuve sont interdits, et ce test
         * porte sur le TRI, pas sur les règles d'ouverture. */
        $b = Attempt::create([
            'user_id' => $this->candidat->id, 'exam_id' => $this->epreuve->id,
            'locale' => 'fr', 'idempotency_key' => (string) Str::uuid7(),
            'kind' => 'training', 'status' => 'submitted',
            'started_at' => now()->addSecond(), 'submitted_at' => now()->addSecond(),
            'last_activity_at' => now()->addSecond(),
            'item_count' => 5, 'correct_count' => 3,
        ]);

        $this->assertSame(
            $b->uuid,
            $this->actingAs($this->candidat)->getJson('/api/v1/me/attempts')->json('data.0.uuid'),
            'À ce stade, B est bien la plus récemment touchée.'
        );

        /* On travaille A. Son OUVERTURE reste antérieure à celle de B : seul un
         * tri sur l'ACTIVITÉ peut la faire remonter. Deux secondes d'écart, les
         * horodatages étant à la seconde (DET-40). */
        $this->travelTo(now()->addSeconds(2));

        $item = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$a['uuid']}")->json('data.items.0');

        $this->actingAs($this->candidat)->putJson(
            "/api/v1/me/attempts/{$a['uuid']}/items/{$item['item_uuid']}",
            ['option_uuid' => $item['question']['options'][0]['uuid'], 'confidence' => 'sure']
        );

        $reponse = $this->actingAs($this->candidat)->getJson('/api/v1/me/attempts');

        $this->assertSame(
            $a['uuid'], $reponse->json('data.0.uuid'),
            'Une tentative travaillée à l\'instant passe devant une tentative ouverte après elle '
            .'mais laissée de côté.'
        );
        $this->assertNotNull($reponse->json('data.0.last_activity_at'));
    }

    public function test_la_reponse_portant_un_chronometre_n_est_pas_stockable(): void
    {
        $attempt = $this->ouvrirDiagnostic();

        foreach ([
            '/api/v1/me/attempts',
            "/api/v1/me/attempts/{$attempt['uuid']}",
        ] as $url) {
            $entete = $this->actingAs($this->candidat)->getJson($url)->headers->get('Cache-Control');

            $this->assertStringContainsString(
                'no-store', (string) $entete,
                "{$url} porte seconds_remaining : rejouée depuis un cache, elle rendrait un chronomètre faux."
            );
        }
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
