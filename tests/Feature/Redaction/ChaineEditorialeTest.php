<?php

namespace Tests\Feature\Redaction;

use App\Http\Controllers\Api\V1\QuestionAdminController;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\Remediation;
use App\Models\Role;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PAS-27 — la chaîne éditoriale par l'API.
 *
 * Ce que ces tests défendent : quelqu'un qui n'est pas développeur peut écrire,
 * faire relire et publier une question — et AUCUNE des règles éditoriales déjà
 * imposées ne s'assouplit au passage.
 */
class ChaineEditorialeTest extends TestCase
{
    use RefreshDatabase;

    private Exam $epreuve;

    private CompetencyNode $noeud;

    private Source $source;

    private User $auteur;

    private User $relecteur;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();

        $this->auteur = $this->membre('auteur@naja7i.ma', 'auteur');
        $this->relecteur = $this->membre('editeur@naja7i.ma', 'editeur');
    }

    private function membre(string $email, ?string $role): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $user->markEmailAsVerified();

        if ($role !== null) {
            $user->memberships()->create([
                'role_id' => Role::where('code', $role)->whereNull('tenant_id')->value('id'),
            ]);
        }

        return $user;
    }

    /**
     * Bascule d'utilisateur en repartant d'une session vierge.
     *
     * `AuthenticateSession`, dans la pile stateful de Sanctum, tue la session
     * dès que l'utilisateur authentifié ne correspond plus à celui dont elle
     * porte l'empreinte. C'est voulu — c'est ce qui met fin aux sessions
     * ouvertes à un changement d'identifiants. Ici, la chaîne éditoriale fait
     * dialoguer un auteur et un relecteur : deux personnes, deux navigateurs.
     */
    private function agirComme(User $user): self
    {
        $this->flushSession();

        return $this->actingAs($user);
    }

    /** @return array<string, mixed> */
    private function charge(array $remplace = []): array
    {
        $remediation = Remediation::firstOrCreate(
            ['competency_node_id' => $this->noeud->id, 'locale' => 'fr'],
            ['title' => 'R', 'content' => 'x', 'estimated_minutes' => 5, 'status' => 'published'],
        );

        return array_replace([
            'exam_code' => $this->epreuve->code,
            'competency_node_uuid' => $this->noeud->uuid,
            'locale' => 'fr',
            'stem' => 'Quel est le stade préopératoire selon Piaget ?',
            'explanation' => 'La justification générale de la bonne réponse.',
            'remediation_uuid' => $remediation->uuid,
            'source_code' => $this->source->code,
            'source_locator' => 'p. 42',
            'options' => [
                ['content' => 'A', 'is_correct' => false, 'rationale' => 'A est fausse.', 'cause' => 'confusion_notions'],
                ['content' => 'B', 'is_correct' => true, 'rationale' => 'B est juste.'],
                ['content' => 'C', 'is_correct' => false, 'rationale' => 'C est fausse.', 'cause' => 'lecture_enonce'],
                ['content' => 'D', 'is_correct' => false, 'rationale' => 'D est fausse.', 'cause' => 'connaissance_absente'],
            ],
        ], $remplace);
    }

    private function rediger(array $remplace = [], ?User $qui = null)
    {
        return $this->agirComme($qui ?? $this->auteur)
            ->postJson('/api/v1/admin/questions', $this->charge($remplace));
    }

    // --- Rédiger ---------------------------------------------------------------

    public function test_une_question_redigee_naît_brouillon_quoi_qu_on_demande(): void
    {
        $reponse = $this->rediger([
            // Champs de transition envoyés exprès : ils ne sont pas assignables.
            'status' => 'published',
            'eligible_for_diagnostic' => true,
        ]);

        $reponse->assertCreated();
        $this->assertSame('draft', $reponse->json('data.status'));
        $this->assertFalse($reponse->json('data.eligible_for_diagnostic'));
        $this->assertNull($reponse->json('data.published_at'));

        $question = Question::where('uuid', $reponse->json('data.uuid'))->firstOrFail();
        $this->assertSame($this->auteur->id, $question->author_id);
        $this->assertCount(4, $question->options);
    }

    public function test_la_cause_est_refusee_sur_la_bonne_reponse(): void
    {
        $charge = $this->charge();
        $charge['options'][1]['cause'] = 'confusion_notions';   // la bonne réponse

        $reponse = $this->agirComme($this->auteur)->postJson('/api/v1/admin/questions', $charge);

        $reponse->assertCreated();

        $bonne = collect($reponse->json('data.options'))->firstWhere('is_correct', true);

        $this->assertNull(
            $bonne['cause'],
            'Une bonne réponse ne porte jamais de cause (PAS-5) : la charge utile ne l\'impose pas.'
        );
    }

    /**
     * Le rédacteur voit ce qui bloque SANS avoir à tenter une publication.
     *
     * Les motifs viennent de `QuestionIntegrityChecker`, pas d'une liste
     * réécrite ici : ce sont exactement ceux que `publish()` opposera.
     */
    public function test_la_reponse_annonce_ce_qui_bloque_la_publication(): void
    {
        $reponse = $this->rediger();

        $blocages = $reponse->json('meta.publication_blockers');

        $this->assertNotEmpty($blocages);
        $this->assertTrue(
            collect($blocages)->contains(fn ($m) => str_contains($m, 'validée pédagogiquement')),
            'Un brouillon n\'est pas publiable, et la raison est dite.'
        );
    }

    // --- Ce qui ne s'assouplit pas -------------------------------------------

    public function test_un_distracteur_sans_cause_bloque_la_publication_pour_diagnostic(): void
    {
        $charge = $this->charge();
        unset($charge['options'][2]['cause']);   // le distracteur C

        $uuid = $this->agirComme($this->auteur)
            ->postJson('/api/v1/admin/questions', $charge)
            ->assertCreated()
            ->json('data.uuid');

        $question = Question::where('uuid', $uuid)->firstOrFail();
        $this->menerJusquAValidation($question);

        $reponse = $this->agirComme($this->relecteur)
            ->postJson("/api/v1/admin/questions/{$uuid}/publish", ['for_diagnostic' => true]);

        $reponse->assertStatus(422);
        $this->assertSame('QUESTION_NOT_PUBLISHABLE', $reponse->json('error.code'));
        $this->assertStringContainsString('cause d\'erreur', $reponse->json('error.message'));

        $this->assertSame('pedagogically_validated', $question->fresh()->status);
    }

    public function test_l_auteur_ne_peut_pas_valider_sa_propre_question(): void
    {
        $uuid = $this->rediger()->assertCreated()->json('data.uuid');
        $question = Question::where('uuid', $uuid)->firstOrFail();

        $transitions = app(QuestionTransitionService::class);
        $transitions->submitForReview($question);
        $transitions->markReviewed($question->fresh(), $this->relecteur);

        $this->expectExceptionMessage('Le valideur ne peut pas être l\'auteur');

        $transitions->validate($question->fresh(), $this->auteur);
    }

    public function test_une_question_publiee_ne_peut_plus_changer_de_source(): void
    {
        $uuid = $this->rediger()->assertCreated()->json('data.uuid');
        $question = Question::where('uuid', $uuid)->firstOrFail();

        $this->menerJusquAValidation($question);
        app(QuestionTransitionService::class)->publish($question->fresh());

        $autre = Source::where('code', '!=', $this->source->code)->first() ?? $this->source;

        /* Le gel est tenu EN BASE par trigger : aucun chemin d'écriture n'y
         * échappe, pas même celui-ci qui contourne le service. */
        $this->expectException(QueryException::class);

        $question->fresh()->contentSources()->attach($autre->id, ['verification' => 'verified']);
    }

    public function test_une_question_publiee_ne_s_amende_plus(): void
    {
        $uuid = $this->rediger()->assertCreated()->json('data.uuid');
        $question = Question::where('uuid', $uuid)->firstOrFail();

        $this->menerJusquAValidation($question);
        app(QuestionTransitionService::class)->publish($question->fresh());

        $reponse = $this->agirComme($this->auteur)
            ->patchJson("/api/v1/admin/questions/{$uuid}", ['stem' => 'Un énoncé réécrit après coup ?']);

        $reponse->assertStatus(409);
        $this->assertSame('QUESTION_FROZEN', $reponse->json('error.code'));

        /* Le trigger de gel refuse aussi, et son message contient la requête
         * SQL et ses valeurs liées. `QueryException` héritant de
         * `RuntimeException`, elle partait au client sous couvert d'un message
         * métier — trouvé par mutation. Rien de la base ne sort d'ici. */
        $message = $reponse->json('error.message');

        foreach (['select', 'update', 'insert', 'SQLSTATE', 'Connection:'] as $fuite) {
            $this->assertStringNotContainsStringIgnoringCase($fuite, $message);
        }
    }

    public function test_un_brouillon_s_amende(): void
    {
        $uuid = $this->rediger()->assertCreated()->json('data.uuid');

        $reponse = $this->agirComme($this->auteur)
            ->patchJson("/api/v1/admin/questions/{$uuid}", ['stem' => 'Un énoncé corrigé avant relecture ?']);

        $reponse->assertOk();
        $this->assertSame('Un énoncé corrigé avant relecture ?', $reponse->json('data.stem'));
    }

    // --- Autorisation ----------------------------------------------------------

    public function test_sans_permission_la_redaction_est_refusee(): void
    {
        $sansRole = $this->membre('candidat@naja7i.ma', null);

        $reponse = $this->agirComme($sansRole)->postJson('/api/v1/admin/questions', $this->charge());

        /* 403 PERMISSION_DENIED, comportement de `RequirePermission` depuis le
         * PAS-9 et couvert par les tests du PAS-11. La règle « 404 jamais 403 »
         * porte sur la ressource d'un AUTRE CANDIDAT, pas sur une permission de
         * personnel — voir le rapport du PAS-27, cette divergence est signalée
         * et non tranchée ici. */
        $reponse->assertStatus(403);
        $this->assertSame('PERMISSION_DENIED', $reponse->json('error.code'));
        $this->assertSame(0, Question::count());
    }

    public function test_la_file_de_relecture_exige_la_permission_de_relire(): void
    {
        $this->agirComme($this->membre('lecteur@naja7i.ma', 'auteur'))
            ->getJson('/api/v1/admin/questions/a-relire')
            ->assertStatus(403);

        $this->agirComme($this->relecteur)
            ->getJson('/api/v1/admin/questions/a-relire')
            ->assertOk();
    }

    // --- Liste et file ---------------------------------------------------------

    public function test_la_liste_est_bornee_et_l_annonce(): void
    {
        $plafond = QuestionAdminController::PLAFOND_LISTE;

        for ($i = 0; $i <= $plafond; $i++) {
            $this->rediger(['stem' => "Énoncé numéro {$i} ?"])->assertCreated();
        }

        $reponse = $this->agirComme($this->relecteur)->getJson('/api/v1/admin/questions');

        $reponse->assertOk();
        $this->assertCount($plafond, $reponse->json('data'));
        $this->assertSame($plafond + 1, $reponse->json('meta.total'));
        $this->assertSame(
            1, $reponse->json('meta.pending'),
            'Ce qui n\'est pas servi est compté et dit, jamais tronqué en silence.'
        );
    }

    public function test_la_liste_se_filtre_par_statut_competence_langue_et_auteur(): void
    {
        $this->rediger()->assertCreated();

        $autreAuteur = $this->membre('auteur2@naja7i.ma', 'auteur');
        $this->rediger(['stem' => 'Un autre énoncé du second auteur ?'], $autreAuteur)->assertCreated();

        $par = fn (string $q) => $this->agirComme($this->relecteur)
            ->getJson("/api/v1/admin/questions?{$q}")->json('meta.total');

        $this->assertSame(2, $par('status=draft'));
        $this->assertSame(0, $par('status=published'));
        $this->assertSame(2, $par('locale=fr'));
        $this->assertSame(0, $par('locale=ar'));
        $this->assertSame(2, $par('competency=SE-PSY-DEV'));
        $this->assertSame(0, $par('competency=SE-SOC-EDU'));
        $this->assertSame(1, $par("author={$autreAuteur->uuid}"));
    }

    public function test_un_filtre_inconnu_est_refuse(): void
    {
        $this->agirComme($this->relecteur)
            ->getJson('/api/v1/admin/questions?status=inexistant')
            ->assertStatus(422);
    }

    public function test_la_file_de_relecture_ne_montre_que_ce_qui_attend_et_le_plus_ancien_d_abord(): void
    {
        $premier = $this->rediger(['stem' => 'Le plus ancien à relire ?'])->json('data.uuid');
        $second = $this->rediger(['stem' => 'Le plus récent à relire ?'])->json('data.uuid');
        $brouillon = $this->rediger(['stem' => 'Celui qui reste en brouillon ?'])->json('data.uuid');

        $transitions = app(QuestionTransitionService::class);

        $transitions->submitForReview(Question::where('uuid', $premier)->firstOrFail());
        $this->travelTo(now()->addSeconds(2));
        $transitions->submitForReview(Question::where('uuid', $second)->firstOrFail());

        $file = $this->agirComme($this->relecteur)
            ->getJson('/api/v1/admin/questions/a-relire')
            ->assertOk();

        $uuids = collect($file->json('data'))->pluck('uuid');

        $this->assertSame(2, $file->json('meta.total'));
        $this->assertNotContains($brouillon, $uuids, 'Un brouillon n\'attend personne.');
        $this->assertSame(
            $premier, $uuids->first(),
            'Le plus ancien d\'abord : servir le plus récent ferait d\'une file une pile.'
        );
    }

    // --- Le plan de rédaction pilote la file ----------------------------------

    /**
     * La couverture nomme un couple (compétence, cause) ; le rédacteur filtre
     * la banque sur ce MÊME code de compétence sans traduction intermédiaire.
     * C'est ce qui relie les deux surfaces, et rien d'autre n'a été ajouté.
     */
    public function test_le_plan_de_redaction_et_la_banque_parlent_le_meme_code(): void
    {
        $this->rediger()->assertCreated();

        $couverture = $this->agirComme($this->relecteur)
            ->getJson("/api/v1/admin/banque/couverture/{$this->epreuve->code}")
            ->assertOk();

        $this->assertSame('couples attendus par au moins un candidat', $couverture->json('meta.scope'));

        $total = $this->agirComme($this->relecteur)
            ->getJson("/api/v1/admin/questions?competency={$this->noeud->code}")
            ->json('meta.total');

        $this->assertSame(1, $total);
    }

    /** Mène une question jusqu'à la validation pédagogique, valideur ≠ auteur. */
    private function menerJusquAValidation(Question $question): void
    {
        $transitions = app(QuestionTransitionService::class);
        $transitions->submitForReview($question);
        $transitions->markReviewed($question->fresh(), $this->relecteur);
        $transitions->validate($question->fresh(), $this->relecteur);
    }
}
