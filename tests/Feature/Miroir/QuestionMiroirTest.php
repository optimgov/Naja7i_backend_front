<?php

namespace Tests\Feature\Miroir;

use App\Contracts\AccessGrant;
use App\Models\AccessGrantRecord;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\CauseRevealCounter;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\ReviewSchedule;
use App\Models\Role;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F05 — la question miroir.
 *
 * Ce que ces tests défendent : après une erreur corrigée, on retend le MÊME
 * piège avec un AUTRE énoncé. Sans cela, la correction est une lecture ; avec
 * elle, c'est une vérification.
 */
class QuestionMiroirTest extends TestCase
{
    use RefreshDatabase;

    private Exam $epreuve;

    private User $candidat;

    private CompetencyNode $noeud;

    private Source $source;

    private User $valideur;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();
        $this->noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        $this->valideur = $this->utilisateur('valideur@naja7i.ma');
        $this->candidat = $this->utilisateur('candidat@naja7i.ma');
        $this->candidat->grantCandidateRole();
        $this->candidat->markEmailAsVerified();
    }

    private function utilisateur(string $email): User
    {
        $u = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $u->markEmailAsVerified();

        return $u;
    }

    /** Questions publiées : distracteur A de cause `confusion_notions`. */
    private function peupler(int $combien, ?CompetencyNode $noeud = null): void
    {
        $noeud ??= $this->noeud;

        $remediation = Remediation::firstOrCreate(
            ['competency_node_id' => $noeud->id, 'locale' => 'fr'],
            ['title' => 'R', 'content' => 'x', 'estimated_minutes' => 5, 'status' => 'published'],
        );

        $transitions = app(QuestionTransitionService::class);

        for ($i = 1; $i <= $combien; $i++) {
            $question = Question::create([
                'exam_id' => $this->epreuve->id,
                'competency_node_id' => $noeud->id,
                'locale' => 'fr',
                'sibling_group' => (string) Str::uuid7(),
                'stem' => "Énoncé {$i} — {$noeud->code}",
                'explanation' => 'Justification.',
                'remediation_id' => $remediation->id,
                'author_id' => $this->utilisateur("auteur-{$noeud->code}-{$i}@naja7i.ma")->id,
            ]);

            foreach ([
                ['A', false, 'confusion_notions'],
                ['B', true, null],
                ['C', false, 'lecture_enonce'],
                ['D', false, 'connaissance_absente'],
            ] as $p => [$c, $juste, $cause]) {
                QuestionOption::create([
                    'question_id' => $question->id, 'position' => $p + 1,
                    'content' => $c, 'is_correct' => $juste, 'rationale' => 'r', 'cause' => $cause,
                ]);
            }

            $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);
            $transitions->submitForReview($question);
            $transitions->markReviewed($question, $this->valideur);
            $transitions->validate($question, $this->valideur);
            $transitions->publish($question, forDiagnostic: true);
        }
    }

    /**
     * Une tentative d'un item sur une question donnée, répondue puis soumise.
     *
     * @param  bool|null  $juste  null pour laisser l'item sans réponse
     */
    private function servir(Question $question, ?bool $juste, ?User $qui = null, bool $soumettre = true): AttemptItem
    {
        $qui ??= $this->candidat;
        $service = app(AttemptService::class);

        $attempt = Attempt::create([
            'user_id' => $qui->id, 'exam_id' => $this->epreuve->id,
            'locale' => 'fr', 'idempotency_key' => (string) Str::uuid7(),
            'kind' => 'training', 'status' => 'in_progress', 'started_at' => now(),
            'item_count' => 1,
        ]);

        $item = AttemptItem::create([
            'attempt_id' => $attempt->id, 'question_id' => $question->id,
            'competency_node_id' => $question->competency_node_id, 'position' => 1,
        ]);

        if ($juste !== null) {
            $service->answer(
                $item,
                $juste ? $question->correctOption() : $question->options->firstWhere('position', 1),
                'sure'
            );
        }

        if ($soumettre) {
            $service->submit($attempt->fresh());
        }

        return $item->fresh();
    }

    private function questions(): Collection
    {
        return Question::where('competency_node_id', $this->noeud->id)
            ->with('options')->orderBy('id')->get();
    }

    private function ouvrirMiroir(AttemptItem $item, ?User $qui = null)
    {
        return $this->actingAs($qui ?? $this->candidat)
            ->postJson("/api/v1/me/mirrors/{$item->uuid}");
    }

    // --- Ce qui n'ouvre aucun miroir ------------------------------------------

    public function test_un_item_juste_n_a_pas_de_miroir(): void
    {
        $this->peupler(2);
        $item = $this->servir($this->questions()->first(), juste: true);

        $reponse = $this->ouvrirMiroir($item);

        $reponse->assertStatus(409);
        $this->assertSame('MIRROR_NOT_APPLICABLE', $reponse->json('error.code'));
        $this->assertSame(0, Attempt::where('kind', 'mirror')->count());
    }

    public function test_un_item_sans_reponse_n_a_pas_de_miroir(): void
    {
        $this->peupler(2);
        $item = $this->servir($this->questions()->first(), juste: null);

        $this->ouvrirMiroir($item)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'MIRROR_NOT_APPLICABLE');
    }

    public function test_une_tentative_non_soumise_n_a_pas_de_miroir(): void
    {
        $this->peupler(2);
        $item = $this->servir($this->questions()->first(), juste: false, soumettre: false);

        $this->ouvrirMiroir($item)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'MIRROR_NOT_APPLICABLE');
    }

    public function test_l_item_d_un_autre_candidat_est_introuvable(): void
    {
        $this->peupler(2);

        $autre = $this->utilisateur('autre@naja7i.ma');
        $autre->grantCandidateRole();

        $item = $this->servir($this->questions()->first(), juste: false, qui: $autre);

        /* 404 et non 403 : lui confirmer que cet item existe renseignerait sur
         * l'activité d'un autre candidat. */
        $this->ouvrirMiroir($item)->assertNotFound();
    }

    public function test_un_couple_sans_autre_question_refuse_avec_un_code_distinct(): void
    {
        // UNE SEULE question pour ce couple : aucune sœur possible.
        $this->peupler(1);
        $item = $this->servir($this->questions()->first(), juste: false);

        $reponse = $this->ouvrirMiroir($item);

        $reponse->assertStatus(409);
        $this->assertSame(
            'MIRROR_NOT_AVAILABLE', $reponse->json('error.code'),
            'Distinct de « sans objet » : ici le candidat a bien une erreur, c\'est la banque qui manque.'
        );
    }

    /**
     * Le refus précédent n'est pas un cas limite : c'est un trou de banque, et
     * il se lit déjà au plan de rédaction du PAS-22 — l'erreur ayant créé un
     * rendez-vous, `CouvertureBanque` recense le couple sans rien de plus.
     */
    public function test_un_couple_sans_miroir_apparait_au_plan_de_redaction(): void
    {
        $this->peupler(1);
        $this->servir($this->questions()->first(), juste: false);

        $editeur = $this->utilisateur('editeur@naja7i.ma');
        $editeur->memberships()->create([
            'role_id' => Role::where('code', 'editeur')->whereNull('tenant_id')->value('id'),
        ]);

        $couples = collect(
            $this->actingAs($editeur)
                ->getJson("/api/v1/admin/banque/couverture/{$this->epreuve->code}")
                ->assertOk()
                ->json('data')
        );

        $trou = $couples->firstWhere('cause', 'confusion_notions');

        $this->assertNotNull($trou, 'Le couple sans miroir doit figurer au plan de rédaction.');
        $this->assertSame('SE-PSY-DEV', $trou['competency']['code']);
        $this->assertSame('no_sibling', $trou['coverage']['fr']['severity']);
    }

    // --- Le miroir lui-même ----------------------------------------------------

    public function test_le_miroir_n_est_jamais_la_question_deja_repondue(): void
    {
        $this->peupler(4);
        $repondue = $this->questions()->first();
        $item = $this->servir($repondue, juste: false);

        $reponse = $this->ouvrirMiroir($item);

        $reponse->assertCreated();
        $this->assertSame('mirror', $reponse->json('data.kind'));
        $this->assertSame(1, $reponse->json('data.item_count'), 'Un seul item : on vérifie un point, pas une série.');

        $servie = Question::where('uuid', $reponse->json('data.items.0.question.uuid'))->firstOrFail();

        $this->assertNotSame(
            $repondue->id, $servie->id,
            'Resservir l\'énoncé corrigé ne vérifierait rien et le ferait croire au candidat.'
        );
        $this->assertSame($this->noeud->id, $servie->competency_node_id);
        $this->assertSame($repondue->uuid, $reponse->json('meta.source_question_uuid'));
    }

    // --- La cause ne s'obtient pas en ouvrant un miroir ------------------------

    /**
     * AUDIT TOURNÉE 2, BLOC-1 — le sens que le test précédent ne vérifiait pas.
     *
     * `meta.cause` était publiée sans rien consulter : un compte gratuit qui
     * n'ouvrait jamais `/correction` récoltait toutes ses causes, un item à la
     * fois. La supposition fautive était que le miroir porte une cause « qu'on
     * vient de voir en correction » — le contrat de route ne garantit aucun
     * ordre.
     */
    public function test_le_miroir_ne_livre_pas_une_cause_non_acquise(): void
    {
        $this->peupler(4);
        $item = $this->servir($this->questions()->first(), juste: false);

        // Aucune correction consultée : rien n'a été payé.
        $reponse = $this->ouvrirMiroir($item)->assertCreated();

        $this->assertNull(
            $reponse->json('meta.cause'),
            'Ouvrir un miroir n\'achète pas le diagnostic qu\'il vérifie.'
        );
        $this->assertTrue($reponse->json('meta.cause_locked'));

        $this->assertSame(
            0,
            (int) CauseRevealCounter::where('user_id', $this->candidat->id)->value('revealed_total'),
            'Et il ne consomme rien non plus : un geste de navigation ne se facture pas.'
        );
    }

    public function test_le_miroir_livre_une_cause_deja_acquise(): void
    {
        $this->peupler(4);
        $item = $this->servir($this->questions()->first(), juste: false);

        // La correction : la cause est payée, une unité consommée.
        $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$item->attempt->uuid}/correction")
            ->assertOk();

        $reponse = $this->ouvrirMiroir($item)->assertCreated();

        $this->assertSame('confusion_notions', $reponse->json('meta.cause'));
        $this->assertFalse($reponse->json('meta.cause_locked'));

        $this->assertSame(
            1,
            (int) CauseRevealCounter::where('user_id', $this->candidat->id)->value('revealed_total'),
            'Une cause acquise se relit sans repayer.'
        );
    }

    public function test_un_abonne_voit_la_cause_du_miroir_sans_correction_prealable(): void
    {
        $this->peupler(4);
        $item = $this->servir($this->questions()->first(), juste: false);

        AccessGrantRecord::create([
            'user_id' => $this->candidat->id,
            'capability' => AccessGrant::CAUSE_REVEAL,
            'starts_at' => now()->subDay(), 'origin' => 'purchase',
        ]);

        $reponse = $this->ouvrirMiroir($item)->assertCreated();

        $this->assertSame('confusion_notions', $reponse->json('meta.cause'));
        $this->assertFalse($reponse->json('meta.cause_locked'));
    }

    public function test_ouvrir_deux_fois_reprend_le_miroir_sans_en_creer_un_second(): void
    {
        $this->peupler(4);
        $item = $this->servir($this->questions()->first(), juste: false);

        $premier = $this->ouvrirMiroir($item);
        $premier->assertCreated();

        $second = $this->ouvrirMiroir($item);

        $second->assertOk();   // 200 : on reprend
        $this->assertSame($premier->json('data.uuid'), $second->json('data.uuid'));
        $this->assertSame(1, Attempt::where('kind', 'mirror')->count());
    }

    public function test_une_cle_reutilisee_pour_un_autre_item_est_refusee(): void
    {
        $this->peupler(4);
        $questions = $this->questions();

        $premier = $this->servir($questions[0], juste: false);
        $second = $this->servir($questions[1], juste: false);

        $cle = (string) Str::uuid7();

        $this->actingAs($this->candidat)
            ->withHeaders(['Idempotency-Key' => $cle])
            ->postJson("/api/v1/me/mirrors/{$premier->uuid}")
            ->assertCreated();

        $this->actingAs($this->candidat)
            ->withHeaders(['Idempotency-Key' => $cle])
            ->postJson("/api/v1/me/mirrors/{$second->uuid}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    // --- La correction annonce, elle ne sert pas ------------------------------

    public function test_la_correction_annonce_l_existence_sans_livrer_la_question(): void
    {
        $this->peupler(4);
        $item = $this->servir($this->questions()->first(), juste: false);

        $reponse = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$item->attempt->uuid}/correction");

        $reponse->assertOk();
        $this->assertTrue($reponse->json('data.0.mirror_available'));

        /* La question du miroir n'a AUCUNE raison de voyager ici : ni énoncé,
         * ni uuid. Éprouvé sur les octets, comme l'index du PAS-23. */
        $corps = $reponse->content();

        foreach ($this->questions()->skip(1) as $autre) {
            $this->assertStringNotContainsString(
                $autre->uuid, $corps,
                'Un énoncé de miroir voyage dans une réponse de CORRECTION : '
                .'les deux surfaces cessent d\'être séparées.'
            );
            $this->assertStringNotContainsString($autre->stem, $corps);
        }
    }

    public function test_la_correction_dit_faux_quand_aucun_miroir_n_existe(): void
    {
        $this->peupler(1);
        $item = $this->servir($this->questions()->first(), juste: false);

        $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$item->attempt->uuid}/correction")
            ->assertOk()
            ->assertJsonPath('data.0.mirror_available', false);
    }

    public function test_un_item_juste_n_annonce_aucun_miroir(): void
    {
        $this->peupler(4);
        $item = $this->servir($this->questions()->first(), juste: true);

        $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$item->attempt->uuid}/correction")
            ->assertOk()
            ->assertJsonPath('data.0.mirror_available', false);
    }

    // --- La boucle de F07 se referme -----------------------------------------

    public function test_reussir_le_miroir_fait_avancer_le_rendez_vous_du_couple(): void
    {
        $this->peupler(4);
        $item = $this->servir($this->questions()->first(), juste: false);

        $rdv = ReviewSchedule::where('user_id', $this->candidat->id)->firstOrFail();
        $this->assertSame(1, $rdv->palier, 'L\'erreur a créé le rendez-vous au premier palier.');
        $this->assertSame(0, $rdv->consecutive_sure);

        $miroir = $this->ouvrirMiroir($item)->assertCreated();

        $attempt = Attempt::where('uuid', $miroir->json('data.uuid'))->firstOrFail();
        $service = app(AttemptService::class);

        foreach ($attempt->items()->with('question.options')->get() as $mirrorItem) {
            $service->answer($mirrorItem, $mirrorItem->question->correctOption(), 'sure');
        }

        $service->submit($attempt->fresh());

        $apres = $rdv->fresh();

        $this->assertNotNull($apres);
        $this->assertSame(
            2, $apres->palier,
            'Le même piège évité sur un AUTRE énoncé : c\'est exactement ce que F07 attend.'
        );
        $this->assertSame(1, $apres->consecutive_sure);
    }

    // --- Le quota, vérifié plutôt que supposé --------------------------------

    /**
     * Le miroir porte par construction une cause que le candidat vient de voir
     * en correction. Depuis le PAS-19, une cause déjà payée reste ouverte : le
     * miroir ne doit donc consommer AUCUNE unité dans le parcours normal.
     */
    public function test_le_miroir_ne_reconsomme_pas_une_cause_deja_payee(): void
    {
        $this->peupler(4);
        $item = $this->servir($this->questions()->first(), juste: false);

        // Correction d'origine : la cause est révélée, une unité est consommée.
        $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$item->attempt->uuid}/correction")
            ->assertOk();

        $apresPremiere = CauseRevealCounter::where('user_id', $this->candidat->id)->value('revealed_total');
        $this->assertSame(1, $apresPremiere);

        // Le miroir, raté lui aussi : même couple, même cause.
        $miroir = $this->ouvrirMiroir($item)->assertCreated();
        $attempt = Attempt::where('uuid', $miroir->json('data.uuid'))->firstOrFail();
        $service = app(AttemptService::class);

        foreach ($attempt->items()->with('question.options')->get() as $mirrorItem) {
            $service->answer($mirrorItem, $mirrorItem->question->options->firstWhere('position', 1), 'sure');
        }

        $service->submit($attempt->fresh());

        $correction = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$attempt->uuid}/correction")
            ->assertOk();

        $this->assertSame(
            'confusion_notions', $correction->json('data.0.options.0.cause'),
            'La cause du miroir est celle déjà payée : elle reste ouverte.'
        );
        $this->assertFalse($correction->json('data.0.cause_locked'));

        $this->assertSame(
            $apresPremiere,
            CauseRevealCounter::where('user_id', $this->candidat->id)->value('revealed_total'),
            'Une cause déjà payée ne se repaie pas — la garantie du PAS-19 vaut pour toute surface.'
        );
    }
}
