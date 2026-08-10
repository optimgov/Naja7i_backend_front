<?php

namespace Tests\Feature\Correctifs;

use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\CauseRevealCounter;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Permission;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\Response;
use App\Models\Role;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\CauseRevealService;
use App\Services\PermissionResolver;
use App\Services\QuestionIntegrityChecker;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\Crmef2025Seeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PAS-11 — Correctifs de la revue PAS-9 / PAS-10.
 *
 * Chaque test emprunte le chemin de contournement EXACT décrit par la revue.
 * Un correctif dont on ne rejoue pas le scénario d'origine n'est pas vérifié.
 */
class CorrectifsRevue2Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $plateforme;

    private Tenant $organisme;

    private Exam $epreuve;

    private Source $source;

    private User $auteur;

    private User $valideur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plateforme = Tenant::where('kind', 'platform')->firstOrFail();
        $this->organisme = Tenant::create(['slug' => 'centre-fes', 'name' => 'Centre de Fès']);

        app(TenantContext::class)->set($this->plateforme);

        $this->seed(CatalogueSeeder::class);
        $this->seed(Crmef2025Seeder::class);

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();

        $this->auteur = $this->utilisateur('auteur@naja7i.ma');
        $this->valideur = $this->utilisateur('valideur@naja7i.ma');
    }

    private function utilisateur(string $email): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $user->markEmailAsVerified();

        return $user->fresh();
    }

    // ===================================================================
    // PAS-9 BLOC-1 — escalade de privilèges inter-tenant
    // ===================================================================

    public function test_une_appartenance_ne_peut_pas_referencer_un_role_d_un_autre_organisme(): void
    {
        $autre = Tenant::create(['slug' => 'centre-agadir', 'name' => 'Centre d\'Agadir']);

        $roleAutre = Role::create([
            'tenant_id' => $autre->id, 'code' => 'coordonnateur',
            'label_fr' => 'Coordonnateur', 'label_ar' => 'منسق',
        ]);

        app(TenantContext::class)->set($this->organisme);

        $this->expectException(QueryException::class);

        $this->auteur->memberships()->create(['role_id' => $roleAutre->id]);
    }

    public function test_un_organisme_ne_peut_pas_attribuer_le_role_super_admin(): void
    {
        $superAdmin = Role::where('code', 'super_admin')->whereNull('tenant_id')->firstOrFail();

        app(TenantContext::class)->set($this->organisme);

        $this->expectException(QueryException::class);

        // Le scénario exact de la revue : escalade vers les pouvoirs plateforme.
        $this->auteur->memberships()->create(['role_id' => $superAdmin->id]);
    }

    public function test_un_organisme_ne_peut_pas_attribuer_un_role_de_back_office(): void
    {
        $editeur = Role::where('code', 'editeur')->whereNull('tenant_id')->firstOrFail();

        app(TenantContext::class)->set($this->organisme);

        $this->expectException(QueryException::class);

        $this->auteur->memberships()->create(['role_id' => $editeur->id]);
    }

    public function test_le_role_candidat_reste_attribuable_dans_un_organisme(): void
    {
        $candidat = Role::where('code', 'candidat')->whereNull('tenant_id')->firstOrFail();

        app(TenantContext::class)->set($this->organisme);
        $membership = $this->auteur->memberships()->create(['role_id' => $candidat->id]);

        $this->assertNotNull($membership->id);
        $this->assertSame($this->organisme->id, $membership->tenant_id);
    }

    public function test_un_role_local_ordinaire_est_attribuable_dans_son_organisme(): void
    {
        $local = Role::create([
            'tenant_id' => $this->organisme->id, 'code' => 'lecteur',
            'label_fr' => 'Lecteur', 'label_ar' => 'قارئ',
        ]);
        $local->permissions()->attach(Permission::where('code', 'members.view')->value('id'));

        app(TenantContext::class)->set($this->organisme);
        $this->auteur->memberships()->create(['role_id' => $local->id]);

        $resolveur = app(PermissionResolver::class);
        $resolveur->forget();

        $this->assertTrue($resolveur->has($this->auteur, 'members.view'));
    }

    public function test_les_permissions_de_plateforme_ne_fuient_pas_vers_un_organisme(): void
    {
        app(TenantContext::class)->set($this->plateforme);
        $superAdmin = Role::where('code', 'super_admin')->whereNull('tenant_id')->firstOrFail();
        $this->auteur->memberships()->create(['role_id' => $superAdmin->id]);

        $resolveur = app(PermissionResolver::class);

        $resolveur->forget();
        $this->assertTrue($resolveur->has($this->auteur, 'tenants.manage'));

        app(TenantContext::class)->set($this->organisme);
        $resolveur->forget();
        $this->assertFalse($resolveur->has($this->auteur, 'tenants.manage'));
    }

    // ===================================================================
    // PAS-9 BLOC-2 — une action réelle consomme les permissions
    // ===================================================================

    public function test_publier_sans_la_permission_est_refuse(): void
    {
        $question = $this->questionValidee();

        app(TenantContext::class)->set($this->plateforme);
        $this->auteur->memberships()->create([
            'role_id' => Role::where('code', 'auteur')->whereNull('tenant_id')->value('id'),
        ]);

        $this->actingAs($this->auteur)
            ->postJson("/api/v1/admin/questions/{$question->uuid}/publish", ['for_diagnostic' => true])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    public function test_publier_avec_la_permission_reussit(): void
    {
        $question = $this->questionValidee();

        app(TenantContext::class)->set($this->plateforme);
        $editeur = $this->utilisateur('editeur@naja7i.ma');
        $editeur->memberships()->create([
            'role_id' => Role::where('code', 'editeur')->whereNull('tenant_id')->value('id'),
        ]);

        $this->actingAs($editeur)
            ->postJson("/api/v1/admin/questions/{$question->uuid}/publish", ['for_diagnostic' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'published');
    }

    public function test_retirer_la_permission_change_le_resultat_sans_redeploiement(): void
    {
        app(TenantContext::class)->set($this->plateforme);
        $editeur = $this->utilisateur('editeur2@naja7i.ma');
        $role = Role::where('code', 'editeur')->whereNull('tenant_id')->firstOrFail();
        $editeur->memberships()->create(['role_id' => $role->id]);

        $premiere = $this->questionValidee();

        $this->actingAs($editeur)
            ->postJson("/api/v1/admin/questions/{$premiere->uuid}/publish", ['for_diagnostic' => true])
            ->assertOk();

        // Le test d'acceptation exact demandé par la revue.
        $role->permissions()->detach(Permission::where('code', 'questions.publish')->value('id'));

        $seconde = $this->questionValidee('seconde@naja7i.ma');

        $this->actingAs($editeur)
            ->postJson("/api/v1/admin/questions/{$seconde->uuid}/publish", ['for_diagnostic' => true])
            ->assertStatus(403);
    }

    // ===================================================================
    // PAS-10 BLOC-1 — la publication ne se contourne plus
    // ===================================================================

    public function test_une_mise_a_jour_eloquent_ne_peut_pas_publier(): void
    {
        $question = $this->questionBrouillon();

        // Le scénario exact de la revue : update() ignore $fillable.
        $this->expectException(QueryException::class);

        Question::whereKey($question->id)->update([
            'status' => 'published',
            'published_at' => now(),
            'eligible_for_diagnostic' => true,
        ]);
    }

    public function test_un_force_fill_ne_peut_pas_publier(): void
    {
        $question = $this->questionBrouillon();

        $this->expectException(QueryException::class);

        $question->forceFill(['status' => 'published', 'published_at' => now()])->save();
    }

    public function test_du_sql_brut_ne_peut_pas_publier(): void
    {
        $question = $this->questionBrouillon();

        $this->expectException(QueryException::class);

        DB::statement(
            "UPDATE questions SET status = 'published', published_at = now() WHERE id = ?",
            [$question->id]
        );
    }

    public function test_une_creation_directe_en_publie_est_refusee(): void
    {
        $noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        $this->expectException(QueryException::class);

        DB::statement(
            "INSERT INTO questions (uuid, exam_id, competency_node_id, locale, stem, status, published_at, created_at, updated_at)
             VALUES (gen_random_uuid(), ?, ?, 'fr', 'Contournement', 'published', now(), now(), now())",
            [$this->epreuve->id, $noeud->id]
        );
    }

    public function test_publier_sans_valideur_est_refuse_meme_par_sql(): void
    {
        $question = $this->questionBrouillon();

        // Amenée à l'état validé, mais sans valideur enregistré.
        DB::statement(
            "UPDATE questions SET status = 'pedagogically_validated' WHERE id = ?",
            [$question->id]
        );

        $this->expectException(QueryException::class);

        DB::statement(
            "UPDATE questions SET status = 'published', published_at = now() WHERE id = ?",
            [$question->id]
        );
    }

    public function test_le_service_et_la_base_s_accordent_sur_le_refus(): void
    {
        $question = $this->questionValidee(sourceVerifiee: false);

        // Le service refuse avec un message lisible…
        $issues = app(QuestionIntegrityChecker::class)
            ->publicationIssues(
                tap($question->fresh(['options', 'exam.taxonomyProfile', 'node']),
                    fn ($q) => $q->eligible_for_diagnostic = true)
            );

        $this->assertNotEmpty($issues);

        // …et la base refuse le même cas, quel que soit le chemin.
        $this->expectException(QueryException::class);

        DB::statement(
            "UPDATE questions SET status = 'published', published_at = now(), eligible_for_diagnostic = true WHERE id = ?",
            [$question->id]
        );
    }

    // ===================================================================
    // PAS-10 BLOC-2 — le gel couvre toutes les colonnes
    // ===================================================================

    /** @dataProvider colonnesGelees */
    public function test_chaque_colonne_d_une_question_publiee_est_gelee(string $colonne, string $sql): void
    {
        $question = $this->questionPubliee();

        $this->expectException(QueryException::class);

        DB::statement("UPDATE questions SET {$sql} WHERE id = ?", [$question->id]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function colonnesGelees(): array
    {
        return [
            'stem' => ['stem', "stem = 'Réécrit'"],
            'explanation' => ['explanation', "explanation = 'Réécrite'"],
            'difficulty' => ['difficulty', 'difficulty = 4'],
            'cognitive_level' => ['cognitive_level', "cognitive_level = 'application'"],
            'remediation_id' => ['remediation_id', 'remediation_id = NULL'],
            /* `mirror_question_id` doit viser une VRAIE modification. Le gel
             * compare la ligne entière : écrire NULL sur une colonne déjà nulle
             * ne produit aucune différence, et le trigger a raison de se taire.
             * Le fixture pose donc un miroir avant publication, pour que le
             * retrait en soit un. */
            'mirror_question_id' => ['mirror_question_id', 'mirror_question_id = NULL'],
            'delayed_review_days' => ['delayed_review_days', 'delayed_review_days = 7'],
            'author_id' => ['author_id', 'author_id = NULL'],
            'validator_id' => ['validator_id', 'validator_id = NULL'],
            'version' => ['version', 'version = 9'],
            'sibling_group' => ['sibling_group', 'sibling_group = NULL'],
            'locale' => ['locale', "locale = 'ar'"],
        ];
    }

    public function test_le_retrait_reste_possible_sur_une_question_publiee(): void
    {
        $question = $this->questionPubliee();

        $retiree = app(QuestionTransitionService::class)->retire($question);

        $this->assertSame('retired', $retiree->status);
    }

    public function test_l_eligibilite_ne_peut_pas_etre_elargie_apres_publication(): void
    {
        $question = $this->questionPubliee(forDiagnostic: false);

        $this->expectException(QueryException::class);

        DB::statement(
            'UPDATE questions SET eligible_for_diagnostic = true WHERE id = ?',
            [$question->id]
        );
    }

    // ===================================================================
    // PAS-10 BLOC-3 — le quota ne se dépasse pas
    // ===================================================================

    public function test_deux_causes_ne_consomment_pas_la_derniere_unite_deux_fois(): void
    {
        $candidat = $this->utilisateur('quota@naja7i.ma');
        app(TenantContext::class)->set($this->plateforme);
        $candidat->grantCandidateRole();

        [$premiere, $seconde] = $this->deuxReponsesFausses($candidat);

        $service = app(CauseRevealService::class);

        // Une unité déjà consommée : il en reste une seule.
        CauseRevealCounter::updateOrCreate(['user_id' => $candidat->id], ['revealed_total' => 1]);

        $this->assertTrue($service->reveal($candidat, $premiere, false));
        $this->assertFalse(
            $service->reveal($candidat, $seconde->fresh(), false),
            'La dernière unité ne peut servir qu\'une fois.'
        );

        $this->assertSame(2, CauseRevealCounter::where('user_id', $candidat->id)->value('revealed_total'));
        $this->assertFalse((bool) $seconde->fresh()->cause_revealed);
    }

    public function test_le_compteur_ne_depasse_jamais_le_plafond(): void
    {
        $candidat = $this->utilisateur('plafond@naja7i.ma');
        app(TenantContext::class)->set($this->plateforme);
        $candidat->grantCandidateRole();

        [$premiere, $seconde] = $this->deuxReponsesFausses($candidat);
        $service = app(CauseRevealService::class);

        $service->reveal($candidat, $premiere, false);
        $service->reveal($candidat, $seconde->fresh(), false);

        $this->assertSame(2, $service->status($candidat, false)['revealed']);
        $this->assertFalse($service->status($candidat, false)['allowed']);
    }

    public function test_revoir_une_cause_deja_revelee_ne_consomme_rien(): void
    {
        $candidat = $this->utilisateur('revoir@naja7i.ma');
        app(TenantContext::class)->set($this->plateforme);
        $candidat->grantCandidateRole();

        [$premiere] = $this->deuxReponsesFausses($candidat);
        $service = app(CauseRevealService::class);

        $service->reveal($candidat, $premiere, false);
        $service->reveal($candidat, $premiere->fresh(), false);

        $this->assertSame(1, $service->status($candidat, false)['revealed']);
    }

    // ===================================================================
    // PAS-10 BLOC-4 — aucune réponse après soumission
    // ===================================================================

    public function test_une_reponse_est_refusee_si_la_tentative_a_ete_soumise_entre_temps(): void
    {
        $candidat = $this->utilisateur('course@naja7i.ma');
        app(TenantContext::class)->set($this->plateforme);
        $candidat->grantCandidateRole();

        $item = $this->itemDeTentative($candidat);
        $service = app(AttemptService::class);

        // Simule la course : la tentative est close pendant que l'appelant
        // détient encore une instance chargée avant fermeture.
        $instanceObsolete = AttemptItem::find($item->id);
        $service->submit($item->attempt);

        $this->expectException(\RuntimeException::class);
        $service->answer($instanceObsolete, $instanceObsolete->question->options->first(), 'sure');
    }

    public function test_la_correction_figee_reste_coherente_avec_les_reponses(): void
    {
        $candidat = $this->utilisateur('coherence@naja7i.ma');
        app(TenantContext::class)->set($this->plateforme);
        $candidat->grantCandidateRole();

        $item = $this->itemDeTentative($candidat);
        $service = app(AttemptService::class);

        $service->answer($item, $item->question->correctOption(), 'sure');
        $clos = $service->submit($item->attempt->fresh());

        $this->assertSame(1, $clos->correct_count);
        $this->assertSame(
            Response::whereNotNull('is_correct')->count(),
            $clos->answered_count,
            'Chaque réponse enregistrée doit avoir été corrigée.'
        );
    }

    // ===================================================================
    // Utilitaires
    // ===================================================================

    private function questionBrouillon(?string $auteurEmail = null): Question
    {
        $auteur = $auteurEmail === null ? $this->auteur : $this->utilisateur($auteurEmail);
        $noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        $remediation = Remediation::firstOrCreate(
            ['competency_node_id' => $noeud->id, 'locale' => 'fr'],
            ['title' => 'Remédiation', 'content' => 'Contenu.', 'estimated_minutes' => 8, 'status' => 'published']
        );

        $question = Question::create([
            'exam_id' => $this->epreuve->id, 'competency_node_id' => $noeud->id,
            'locale' => 'fr', 'sibling_group' => (string) Str::uuid7(),
            'stem' => 'Énoncé '.Str::random(5).' ?', 'explanation' => 'Justification.',
            'remediation_id' => $remediation->id, 'author_id' => $auteur->id,
        ]);

        foreach ([
            ['A', false, 'A est fausse.', 'confusion_notions'],
            ['B', true,  'B est juste.',  null],
            ['C', false, 'C est fausse.', 'lecture_enonce'],
            ['D', false, 'D est fausse.', 'connaissance_absente'],
        ] as $p => [$c, $juste, $justif, $cause]) {
            QuestionOption::create([
                'question_id' => $question->id, 'position' => $p + 1,
                'content' => $c, 'is_correct' => $juste, 'rationale' => $justif, 'cause' => $cause,
            ]);
        }

        return $question->fresh('options');
    }

    private function questionValidee(?string $auteurEmail = null, bool $sourceVerifiee = true): Question
    {
        $question = $this->questionBrouillon($auteurEmail);

        if ($sourceVerifiee) {
            $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);
        }

        $service = app(QuestionTransitionService::class);
        $service->submitForReview($question);
        $service->markReviewed($question->fresh(), $this->valideur);
        $service->validate($question->fresh(), $this->valideur);

        return $question->fresh('options');
    }

    private function questionPubliee(bool $forDiagnostic = true): Question
    {
        $question = $this->questionValidee();

        /* Une question miroir, posée AVANT publication : sans elle,
         * `mirror_question_id` reste nul et le cas de gel correspondant ne
         * prouverait rien — écrire NULL sur NULL n'est pas une modification. */
        if ($question->mirror_question_id === null) {
            $question->forceFill(['mirror_question_id' => $this->questionValidee()->id])->save();
        }

        return app(QuestionTransitionService::class)
            ->publish($question, forDiagnostic: $forDiagnostic)
            ->load('options');
    }

    private function itemDeTentative(User $candidat): AttemptItem
    {
        $question = $this->questionPubliee();

        $attempt = Attempt::create([
            'user_id' => $candidat->id, 'exam_id' => $this->epreuve->id,
            'locale' => 'fr', 'idempotency_key' => (string) Str::uuid7(),
            'kind' => 'training', 'status' => 'in_progress',
            'started_at' => now(), 'item_count' => 1,
        ]);

        return AttemptItem::create([
            'attempt_id' => $attempt->id, 'question_id' => $question->id,
            'competency_node_id' => $question->competency_node_id, 'position' => 1,
        ])->fresh(['attempt', 'question.options']);
    }

    /** @return array{0: Response, 1: Response} */
    private function deuxReponsesFausses(User $candidat): array
    {
        $service = app(AttemptService::class);
        $reponses = [];

        foreach ([1, 2] as $i) {
            $item = $this->itemDeTentative($candidat);
            $service->answer($item, $item->question->distractors()->first(), 'hesitant');
            $service->submit($item->attempt);
            $reponses[] = $item->fresh()->response;
        }

        return $reponses;
    }
}
