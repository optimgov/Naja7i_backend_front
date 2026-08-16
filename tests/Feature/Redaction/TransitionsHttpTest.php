<?php

namespace Tests\Feature\Redaction;

use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\Remediation;
use App\Models\Role;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Les transitions intermédiaires de la chaîne éditoriale, par l'API — PAS-33.
 *
 * LE TROU : `publish` et `retire` avaient leur route depuis le PAS-11, les
 * trois étapes qui les précèdent n'existaient qu'en Filament. Un appelant
 * programmatique — le semis de la banque de recette du frontend — ne pouvait
 * pas mener une question du brouillon au publié sans passer sous l'API.
 *
 * CES ROUTES N'AJOUTENT AUCUNE RÈGLE. Elles exposent `QuestionTransitionService`
 * et traduisent son refus. Ce que ces tests défendent est donc double : que la
 * chaîne se parcourt bout en bout, et que chaque geste reste sous SA
 * permission — soumettre, relire et valider ne sont pas le même métier.
 */
class TransitionsHttpTest extends TestCase
{
    use RefreshDatabase;

    private Exam $epreuve;

    private CompetencyNode $noeud;

    private Source $source;

    private User $auteur;

    private User $reviseur;

    private User $editeur;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();

        $this->auteur = $this->membre('auteur@naja7i.ma', 'auteur');
        $this->reviseur = $this->membre('reviseur@naja7i.ma', 'reviseur');
        $this->editeur = $this->membre('editeur@naja7i.ma', 'editeur');
    }

    private function membre(string $email, string $role): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $user->markEmailAsVerified();
        $user->memberships()->create([
            'role_id' => Role::where('code', $role)->whereNull('tenant_id')->value('id'),
        ]);

        return $user;
    }

    /** Deux métiers, deux navigateurs : `AuthenticateSession` exige une session vierge. */
    private function agirComme(User $user): self
    {
        $this->flushSession();

        return $this->actingAs($user);
    }

    /** @return array<string, mixed> */
    private function charge(): array
    {
        $remediation = Remediation::firstOrCreate(
            ['competency_node_id' => $this->noeud->id, 'locale' => 'fr'],
            ['title' => 'R', 'content' => 'x', 'estimated_minutes' => 5, 'status' => 'published'],
        );

        return [
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
                ['content' => 'Aucune des propositions précédentes', 'is_correct' => false, 'rationale' => 'Elle est fausse puisqu’une autre proposition est correcte.', 'cause' => 'indetermine'],
            ],
        ];
    }

    private function rediger(?User $qui = null): string
    {
        return $this->agirComme($qui ?? $this->auteur)
            ->postJson('/api/v1/admin/questions', $this->charge())
            ->assertCreated()
            ->json('data.uuid');
    }

    // --- La chaîne complète, par l'API et rien d'autre --------------------------

    /**
     * LE SCÉNARIO QUI MOTIVE CE PAS : semer une banque de recette sans tinker.
     *
     * Chaque étape est faite par le métier qui la porte, et l'état rendu par la
     * réponse est celui qu'on attend — sans relecture intermédiaire.
     */
    public function test_la_chaine_se_parcourt_de_bout_en_bout_par_l_api(): void
    {
        $uuid = $this->rediger();

        $this->agirComme($this->auteur)
            ->postJson("/api/v1/admin/questions/{$uuid}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'a_verifier');

        $this->agirComme($this->reviseur)
            ->postJson("/api/v1/admin/questions/{$uuid}/review")
            ->assertOk()
            ->assertJsonPath('data.status', 'reviewed');

        $this->agirComme($this->editeur)
            ->postJson("/api/v1/admin/questions/{$uuid}/validate")
            ->assertOk()
            ->assertJsonPath('data.status', 'pedagogically_validated');

        /*
         * LE CONTRÔLE DOCUMENTAIRE FAIT PARTIE DE LA RECETTE, et l'oubli m'a
         * coûté une exécution rouge : publier POUR LE DIAGNOSTIC exige une
         * source vérifiée, et citer une source ne la vérifie pas (DET-46). La
         * route existe depuis le PAS-28 ; c'est bien elle que le semis de CI
         * devra appeler, et non un raccourci en base.
         */
        $this->agirComme($this->reviseur)
            ->postJson("/api/v1/admin/sources/{$this->source->uuid}/verify")
            ->assertOk();

        $this->agirComme($this->editeur)
            ->postJson("/api/v1/admin/questions/{$uuid}/publish", ['for_diagnostic' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $question = Question::where('uuid', $uuid)->firstOrFail();

        $this->assertTrue($question->eligible_for_diagnostic);
        $this->assertSame($this->reviseur->id, $question->reviewer_id, 'Qui a relu est enregistré.');
        $this->assertSame($this->editeur->id, $question->validator_id, 'Qui a validé aussi.');
    }

    // --- Chacune sous SA permission ---------------------------------------------

    /**
     * 403 EXPLICITE, ET C'EST LA RÈGLE CLARIFIÉE AU PAS-28.
     *
     * La règle « 404, jamais 403 » vise l'ÉNUMÉRATION des ressources d'autrui.
     * Une permission de personnel refusée répond 403 : le refusé sait déjà que
     * la surface d'administration existe, et lui répondre « introuvable »
     * masquerait la raison sans rien protéger.
     */
    public function test_soumettre_exige_questions_create(): void
    {
        $uuid = $this->rediger();

        /* Le réviseur porte `questions.review` mais pas `questions.create` :
         * relire n'est pas écrire. */
        $this->agirComme($this->reviseur)
            ->postJson("/api/v1/admin/questions/{$uuid}/submit")
            ->assertForbidden();

        $this->assertSame('draft', Question::where('uuid', $uuid)->value('status'));

        $this->agirComme($this->auteur)
            ->postJson("/api/v1/admin/questions/{$uuid}/submit")
            ->assertOk();
    }

    public function test_relire_exige_questions_review(): void
    {
        $uuid = $this->rediger();
        $this->agirComme($this->auteur)->postJson("/api/v1/admin/questions/{$uuid}/submit")->assertOk();

        $this->agirComme($this->auteur)
            ->postJson("/api/v1/admin/questions/{$uuid}/review")
            ->assertForbidden();

        $this->assertSame('a_verifier', Question::where('uuid', $uuid)->value('status'));

        $this->agirComme($this->reviseur)
            ->postJson("/api/v1/admin/questions/{$uuid}/review")
            ->assertOk();
    }

    public function test_valider_exige_questions_validate(): void
    {
        $uuid = $this->menerJusquARelue();

        /* Le réviseur a relu, mais valider pédagogiquement est un autre geste :
         * le référentiel du PAS-9 les distingue par deux permissions. */
        $this->agirComme($this->reviseur)
            ->postJson("/api/v1/admin/questions/{$uuid}/validate")
            ->assertForbidden();

        $this->assertSame('reviewed', Question::where('uuid', $uuid)->value('status'));

        $this->agirComme($this->editeur)
            ->postJson("/api/v1/admin/questions/{$uuid}/validate")
            ->assertOk();
    }

    // --- Les refus du service, traduits sans être réécrits ----------------------

    /**
     * L'AUTEUR NE VALIDE PAS SA PROPRE QUESTION, PAR LA ROUTE NON PLUS.
     *
     * C'est un ÉDITEUR qui rédige ici, et c'est tout le test : le faire écrire
     * par le rôle `auteur` ne prouverait rien, puisqu'il n'a pas
     * `questions.validate` et recevrait 403 pour une raison sans rapport. Il
     * faut quelqu'un qui PEUT valider et qui a écrit la question — le même
     * piège que le test du bouton, au lot A4.
     */
    public function test_l_auteur_ne_valide_pas_sa_propre_question_par_la_route(): void
    {
        $uuid = $this->menerJusquARelue(auteur: $this->editeur);

        $this->agirComme($this->editeur)
            ->postJson("/api/v1/admin/questions/{$uuid}/validate")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'QUESTION_TRANSITION_REFUSED');

        $this->assertSame(
            'reviewed', Question::where('uuid', $uuid)->value('status'),
            'Un refus ne laisse pas la question à mi-chemin.'
        );

        /* L'autre sens : un second éditeur, qui n'est pas l'auteur, valide. */
        $autre = $this->membre('editeur2@naja7i.ma', 'editeur');

        $this->agirComme($autre)
            ->postJson("/api/v1/admin/questions/{$uuid}/validate")
            ->assertOk()
            ->assertJsonPath('data.status', 'pedagogically_validated');
    }

    public function test_une_transition_invalide_depuis_l_etat_courant_est_refusee(): void
    {
        $uuid = $this->rediger();

        /* Valider un BROUILLON : la table des transitions ne connaît pas cette
         * arête. Le refus vient du service, pas de la route. */
        $this->agirComme($this->editeur)
            ->postJson("/api/v1/admin/questions/{$uuid}/validate")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'QUESTION_TRANSITION_REFUSED');

        $this->agirComme($this->reviseur)
            ->postJson("/api/v1/admin/questions/{$uuid}/review")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'QUESTION_TRANSITION_REFUSED');

        $this->assertSame('draft', Question::where('uuid', $uuid)->value('status'));
    }

    public function test_soumettre_deux_fois_est_refuse_et_non_silencieux(): void
    {
        $uuid = $this->rediger();

        $this->agirComme($this->auteur)->postJson("/api/v1/admin/questions/{$uuid}/submit")->assertOk();

        /* `a_verifier` → `a_verifier` n'est pas une arête : le second appel
         * échoue au lieu de faire croire qu'il a agi. */
        $this->agirComme($this->auteur)
            ->postJson("/api/v1/admin/questions/{$uuid}/submit")
            ->assertStatus(422);
    }

    public function test_une_question_inconnue_reste_introuvable(): void
    {
        $this->agirComme($this->auteur)
            ->postJson('/api/v1/admin/questions/'.Str::uuid7().'/submit')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    /**
     * La réponse rend la ressource COMPLÈTE, blocages compris.
     *
     * Un appelant qui enchaîne la chaîne — c'est le cas du semis de recette —
     * apprend à chaque étape ce qui bloquera la publication, sans relecture
     * intermédiaire.
     */
    public function test_la_reponse_porte_les_blocages_de_publication(): void
    {
        $uuid = $this->rediger();

        $this->agirComme($this->auteur)
            ->postJson("/api/v1/admin/questions/{$uuid}/submit")
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['publication_blockers', 'diagnostic_blockers']]);
    }

    /** Rédige, soumet, fait relire. L'auteur est paramétrable pour le test du valideur. */
    private function menerJusquARelue(?User $auteur = null): string
    {
        $auteur ??= $this->auteur;
        $uuid = $this->rediger($auteur);

        $this->agirComme($auteur)->postJson("/api/v1/admin/questions/{$uuid}/submit")->assertOk();
        $this->agirComme($this->reviseur)->postJson("/api/v1/admin/questions/{$uuid}/review")->assertOk();

        return $uuid;
    }
}
