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

    /* TROIS ACTES, TROIS PERSONNES. Le valideur n'est ni l'auteur ni le
     * relecteur depuis le 17 aout : la fixture porte donc un troisieme compte,
     * au lieu de faire jouer deux roles au meme. */
    private User $valideur;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();

        $this->auteur = $this->membre('auteur@naja7i.ma', 'auteur');
        $this->relecteur = $this->membre('editeur@naja7i.ma', 'editeur');
        $this->valideur = $this->membre('valideur@naja7i.ma', 'editeur');
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
                /* L'option E de l'épreuve réelle depuis 2024 — corpus §4.2.1.
                 * Sa cause est de MÉTHODE et non de connaissance : aucun des
                 * huit codes ne la porte, `indetermine` le dit sans inventer. */
                ['content' => 'Aucune des propositions précédentes', 'is_correct' => false, 'rationale' => 'Elle est fausse puisqu’une autre proposition est correcte.', 'cause' => 'indetermine'],
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
        $this->assertCount(5, $question->options);
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

    // --- Le contrôle documentaire (DET-46) -------------------------------------

    /**
     * VÉRIFIER QUALIFIE LA SOURCE, PAS LA CITATION.
     *
     * Une source est citée par plusieurs questions ; la vérifier une fois
     * profite à toutes. En faire un acte par question ferait recontrôler vingt
     * fois le même arrêté sans garantir que les vingt verdicts concordent.
     */
    public function test_verifier_une_source_profite_a_toutes_ses_citations(): void
    {
        $premiere = $this->rediger(['stem' => 'La première question qui cite cet arrêté ?'])->json('data.uuid');
        $seconde = $this->rediger(['stem' => 'La seconde question qui cite le même arrêté ?'])->json('data.uuid');

        foreach ([$premiere, $seconde] as $uuid) {
            $this->assertSame(
                'unverified',
                $this->agirComme($this->relecteur)->getJson("/api/v1/admin/questions/{$uuid}")
                    ->json('data.sources.0.verification'),
                'Citer une source n\'est pas la vérifier.'
            );
        }

        $reponse = $this->agirComme($this->relecteur)
            ->postJson("/api/v1/admin/sources/{$this->source->uuid}/verify");

        $reponse->assertOk();
        $this->assertSame(2, $reponse->json('meta.citations_updated'));

        /* QUI et QUAND, et c'est la valeur du champ : une vérification anonyme
         * n'engage personne. */
        $this->assertNotNull($reponse->json('data.verified_at'));
        $this->assertSame($this->relecteur->uuid, $reponse->json('data.verified_by_uuid'));

        foreach ([$premiere, $seconde] as $uuid) {
            $this->assertSame(
                'verified',
                $this->agirComme($this->relecteur)->getJson("/api/v1/admin/questions/{$uuid}")
                    ->json('data.sources.0.verification')
            );
        }
    }

    public function test_une_question_redigee_apres_verification_cite_une_source_verifiee(): void
    {
        $this->agirComme($this->relecteur)
            ->postJson("/api/v1/admin/sources/{$this->source->uuid}/verify")
            ->assertOk();

        $uuid = $this->rediger()->assertCreated()->json('data.uuid');

        $this->assertSame(
            'verified',
            $this->agirComme($this->relecteur)->getJson("/api/v1/admin/questions/{$uuid}")
                ->json('data.sources.0.verification'),
            'Une source déjà contrôlée n\'a pas à l\'être une seconde fois parce qu\'une question de plus s\'y appuie.'
        );
    }

    /**
     * La chaîne va désormais jusqu'au bout — c'était l'objet de DET-46.
     *
     * Avant le PAS-28, rien ne posait `verification = 'verified'` : un
     * rédacteur pouvait écrire, faire relire, faire valider, et la publication
     * pour diagnostic était refusée sans qu'aucune surface ne lève le blocage.
     */
    public function test_la_chaine_va_de_la_redaction_a_la_publication_pour_diagnostic(): void
    {
        $uuid = $this->rediger()->assertCreated()->json('data.uuid');

        $this->agirComme($this->relecteur)
            ->postJson("/api/v1/admin/sources/{$this->source->uuid}/verify")
            ->assertOk();

        $question = Question::where('uuid', $uuid)->firstOrFail();
        $this->menerJusquAValidation($question);

        $reponse = $this->agirComme($this->relecteur)
            ->postJson("/api/v1/admin/questions/{$uuid}/publish", ['for_diagnostic' => true]);

        $reponse->assertOk();
        $this->assertSame('published', $reponse->json('data.status'));
        $this->assertTrue($reponse->json('data.eligible_for_diagnostic'));
    }

    public function test_verifier_une_source_exige_la_permission_de_relire(): void
    {
        $this->agirComme($this->auteur)
            ->postJson("/api/v1/admin/sources/{$this->source->uuid}/verify")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    /**
     * Les citations d'une question PUBLIÉE ne bougent pas.
     *
     * Elles sont gelées depuis la contre-revue du PAS-12, et pour une raison
     * qui tient toujours : la correction déjà servie s'appuyait sur l'état
     * d'alors. Vérifier une source après coup ne rend donc pas rétroactivement
     * une question éligible au diagnostic — il faut une nouvelle version.
     */
    public function test_la_verification_ne_degele_pas_une_citation_publiee(): void
    {
        $uuid = $this->rediger()->assertCreated()->json('data.uuid');
        $question = Question::where('uuid', $uuid)->firstOrFail();

        $this->menerJusquAValidation($question);
        app(QuestionTransitionService::class)->publish($question->fresh());

        $reponse = $this->agirComme($this->relecteur)
            ->postJson("/api/v1/admin/sources/{$this->source->uuid}/verify");

        $reponse->assertOk();
        $this->assertSame(
            0, $reponse->json('meta.citations_updated'),
            'La citation gelée est laissée telle quelle, et la vérification aboutit quand même.'
        );

        $this->assertSame(
            'unverified',
            $question->fresh()->contentSources->first()->pivot->verification
        );
    }

    // --- Une source modifiée cesse d'être vérifiée (DET-47) --------------------

    /**
     * MESURE D'ATTENTE, ET ELLE ÉCHOUE DU BON CÔTÉ.
     *
     * Rien n'empêchait de modifier une source APRÈS son contrôle : la ligne
     * attestait d'un document qui n'existait plus. Après invalidation, la
     * publication pour diagnostic se bloque jusqu'à re-vérification — un
     * relecteur recontrôle un texte qu'il vient de corriger, plutôt qu'une
     * plateforme affirme avoir lu ce qu'elle n'a pas lu.
     */
    public function test_modifier_une_source_verifiee_annule_sa_verification(): void
    {
        $this->agirComme($this->relecteur)
            ->postJson("/api/v1/admin/sources/{$this->source->uuid}/verify")
            ->assertOk();

        $this->assertNotNull($this->source->fresh()->verified_at);

        // Le titre change : ce n'est plus le document qui a été contrôlé.
        $this->source->fresh()->update(['title_fr' => 'Un arrêté au titre réécrit']);

        $apres = $this->source->fresh();

        $this->assertNull($apres->verified_at, 'La source modifiée cesse d\'être vérifiée.');
        $this->assertNull($apres->verified_by, 'Et personne n\'en répond plus.');
    }

    /**
     * L'invalidation RÉTROGRADE aussi les citations, et ce n'est pas accessoire.
     *
     * Ce que lisent `hasVerifiedContentSource()` et le trigger de publication
     * est le PIVOT, propagé au moment du contrôle. Sans rétrogradation, la
     * source cesserait d'être vérifiée pendant que les questions continueraient
     * de se publier au diagnostic sur la foi d'un drapeau périmé.
     */
    public function test_l_invalidation_rebloque_la_publication_pour_diagnostic(): void
    {
        $uuid = $this->rediger()->assertCreated()->json('data.uuid');

        $this->agirComme($this->relecteur)
            ->postJson("/api/v1/admin/sources/{$this->source->uuid}/verify")
            ->assertOk();

        $question = Question::where('uuid', $uuid)->firstOrFail();
        $this->menerJusquAValidation($question);

        // La source est corrigée entre la validation et la publication.
        $this->source->fresh()->update(['url' => 'https://exemple.ma/un-autre-document.pdf']);

        $this->assertSame(
            'unverified',
            $question->fresh()->contentSources->first()->pivot->verification,
            'La citation suit la source : sinon le défaut se déplace d\'une table.'
        );

        $reponse = $this->agirComme($this->relecteur)
            ->postJson("/api/v1/admin/questions/{$uuid}/publish", ['for_diagnostic' => true]);

        $reponse->assertStatus(422);
        $this->assertStringContainsString('source de contenu vérifiée', $reponse->json('error.message'));

        // Re-vérifier débloque : la mesure n'est pas une impasse.
        $this->agirComme($this->relecteur)
            ->postJson("/api/v1/admin/sources/{$this->source->uuid}/verify")
            ->assertOk();

        $this->agirComme($this->relecteur)
            ->postJson("/api/v1/admin/questions/{$uuid}/publish", ['for_diagnostic' => true])
            ->assertOk();
    }

    /**
     * Une correction SANS portée documentaire ne désarme pas le contrôle.
     *
     * `location_note_*` aide à trouver le document sans le constituer.
     * Invalider là-dessus ferait crier le garde-fou pour une note de bas de
     * page, et un garde-fou qui crie pour rien finit désarmé.
     */
    public function test_une_note_de_localisation_ne_desarme_pas_le_controle(): void
    {
        $this->agirComme($this->relecteur)
            ->postJson("/api/v1/admin/sources/{$this->source->uuid}/verify")
            ->assertOk();

        $this->source->fresh()->update(['location_note_fr' => 'Consultable au SCD, salle 2.']);

        $this->assertNotNull(
            $this->source->fresh()->verified_at,
            'La liste des colonnes porteuses de sens est délibérée, pas exhaustive par défaut.'
        );
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

        /* L’horloge est rendue : un temps figé se paie dans un test ULTÉRIEUR. */
        $this->travelBack();
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
    /*
     * ══════════════════════════════════════════════════════════════════════
     * BLOC-3 DE L'AUDIT TOURNÉE 3 — LE PATCH RÉPONDAIT SUCCÈS SUR UN ÉTAT
     * PARTIEL.
     *
     * `update()` transformait TOUTES les règles de rédaction en règles de
     * `PATCH` — donc `exam_code`, `locale`, `remediation_uuid`, `source_code`
     * et `source_locator` étaient VALIDÉS. Mais le tableau passé au service ne
     * reprenait que l'énoncé, l'explication, la difficulté, le type et la
     * compétence.
     *
     * `PATCH {"locale":"ar"}` répondait donc 200 avec `fr` toujours en base.
     * Un succès partiel silencieux est pire qu'un refus : le rédacteur relit un
     * brouillon qui n'est pas celui qui est persisté.
     * ══════════════════════════════════════════════════════════════════════
     */

    public function test_amender_la_langue_l_applique_reellement(): void
    {
        $uuid = $this->rediger()->json('data.uuid');

        $this->agirComme($this->auteur)
            ->patchJson("/api/v1/admin/questions/{$uuid}", ['locale' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'ar');

        $this->assertSame('ar', Question::where('uuid', $uuid)->value('locale'));
    }

    public function test_amender_la_remediation_l_applique_reellement(): void
    {
        $uuid = $this->rediger()->json('data.uuid');

        $autre = Remediation::create([
            'competency_node_id' => $this->noeud->id, 'locale' => 'fr',
            'title' => 'Autre remédiation', 'content' => 'x',
            'estimated_minutes' => 9, 'status' => 'published',
        ]);

        $this->agirComme($this->auteur)
            ->patchJson("/api/v1/admin/questions/{$uuid}", ['remediation_uuid' => $autre->uuid])
            ->assertOk();

        $this->assertSame(
            $autre->id,
            Question::where('uuid', $uuid)->value('remediation_id'),
        );
    }

    public function test_un_champ_non_amendable_est_refuse_en_nommant_le_champ(): void
    {
        /*
         * SOIT LE CHAMP EST APPLIQUÉ, SOIT LA REQUÊTE EST REFUSÉE EN LE NOMMANT.
         *
         * `exam_code` déplacerait la question vers une autre épreuve en laissant
         * son nœud de compétence pointer sur l'arbre de l'ancienne. Le refuser
         * est une décision ; le valider puis l'ignorer n'en est pas une.
         */
        $uuid = $this->rediger()->json('data.uuid');

        $this->agirComme($this->auteur)
            ->patchJson("/api/v1/admin/questions/{$uuid}", ['exam_code' => 'AUTRE-CODE'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame(
            $this->epreuve->id,
            Question::where('uuid', $uuid)->value('exam_id'),
        );
    }

    public function test_amender_les_options_passe_par_le_service(): void
    {
        $uuid = $this->rediger()->json('data.uuid');

        $this->agirComme($this->auteur)
            ->patchJson("/api/v1/admin/questions/{$uuid}", [
                'options' => [
                    ['content' => 'A modifiée', 'is_correct' => false, 'rationale' => 'A est ainsi.', 'cause' => 'calcul'],
                    ['content' => 'B modifiée', 'is_correct' => true, 'rationale' => 'B est ainsi.'],
                    ['content' => 'C modifiée', 'is_correct' => false, 'rationale' => 'C est ainsi.', 'cause' => 'lecture_enonce'],
                    ['content' => 'D modifiée', 'is_correct' => false, 'rationale' => 'D est ainsi.', 'cause' => 'connaissance_absente'],
                ],
            ])
            ->assertOk();

        $question = Question::where('uuid', $uuid)->with('options')->firstOrFail();

        $this->assertTrue($question->options->contains('content', 'A modifiée'));

        /* LA CAUSE POSÉE SUR LA BONNE RÉPONSE EST TOUJOURS RETIRÉE PAR LE
         * SERVICE — l'amendement ne doit pas être une porte de contournement. */
        $this->assertNull($question->options->firstWhere('is_correct', true)->cause);
    }

    public function test_un_amendement_qui_echoue_ne_laisse_rien_derriere_lui(): void
    {
        $uuid = $this->rediger()->json('data.uuid');
        $avant = Question::where('uuid', $uuid)->value('stem');

        /* Deux bonnes réponses : l'invariant de base refuse. L'énoncé envoyé
         * dans la même requête ne doit pas survivre au refus. */
        $this->agirComme($this->auteur)
            ->patchJson("/api/v1/admin/questions/{$uuid}", [
                'stem' => 'Un énoncé qui ne doit pas rester',
                'options' => [
                    ['content' => 'A', 'is_correct' => true, 'rationale' => 'A est ainsi.'],
                    ['content' => 'B', 'is_correct' => true, 'rationale' => 'B est ainsi.'],
                    ['content' => 'C', 'is_correct' => false, 'rationale' => 'C est ainsi.', 'cause' => 'calcul'],
                    ['content' => 'D', 'is_correct' => false, 'rationale' => 'D est ainsi.', 'cause' => 'lecture_enonce'],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame($avant, Question::where('uuid', $uuid)->value('stem'));
    }

    private function menerJusquAValidation(Question $question): void
    {
        $transitions = app(QuestionTransitionService::class);
        $transitions->submitForReview($question);
        $transitions->markReviewed($question->fresh(), $this->relecteur);
        $transitions->validate($question->fresh(), $this->valideur);
    }
}
