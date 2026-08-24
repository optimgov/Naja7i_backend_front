<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Enums\QuotaPeriodicity;
use App\Exceptions\PeriodiciteNonImplementee;
use App\Models\AccessGrantRecord;
use App\Models\Attempt;
use App\Models\Audience;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Plan;
use App\Models\Question;
use App\Models\QuestionConsumption;
use App\Models\QuestionOption;
use App\Models\QuotaProfile;
use App\Models\Remediation;
use App\Models\ReviewSchedule;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\EnveloppeDeQuestions;
use App\Services\OffreGratuiteService;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\OuvreLesDroits;
use Tests\TestCase;

/**
 * La consommation des questions — lot 3B.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE SEUL MUR QUI COMPTE DES UNITÉS
 *
 * M-007 a fermé cinq capacités par présence ou absence : une erreur y produit
 * une porte fermée, et cela se voit. Ici une capacité est détenue ET comptée,
 * et une erreur produit un CHIFFRE FAUX — qui ne se voit pas, et que le
 * candidat lit comme une mesure.
 *
 * D'où la forme de ce fichier : il ne vérifie pas seulement que le compteur
 * descend, il vérifie **ce qui ne doit pas bouger**. Le reliquat après une
 * réponse, après une file hors ligne rejouée, après un miroir, après une séance
 * mémoire, après l'expiration d'un forfait illimité. Les non-mouvements sont la
 * moitié difficile.
 */
class ConsommationDesQuestionsTest extends TestCase
{
    use OuvreLesDroits, RefreshDatabase;

    private Exam $epreuve;

    private Source $source;

    private User $valideur;

    private User $candidat;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();
        $this->valideur = $this->utilisateur('valideur-3b@naja7i.ma');

        $this->candidat = $this->utilisateur('candidat-3b@naja7i.ma');
        $this->candidat->markEmailAsVerified();
        $this->candidat->grantCandidateRole();
        $this->candidat = $this->candidat->fresh();

        /* Ce fichier éprouve les reliquats longs et conserve donc explicitement
         * le contrat historique à 40. La v1.1 passe le courant à 10, mais elle
         * ne migre jamais un octroi déjà composé — précisément l'invariant que
         * cette installation historique rend visible. */
        Plan::autoGranted()->sole()->update([
            'quota_profile_id' => QuotaProfile::where('code', 'decouverte')->value('id'),
        ]);
        app(OffreGratuiteService::class)->attribuer($this->candidat);
        $this->candidat = $this->candidat->fresh();

        $this->peuplerLaBanque(8);
    }

    private function utilisateur(string $email): User
    {
        return User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
    }

    private function service(): EnveloppeDeQuestions
    {
        return app(EnveloppeDeQuestions::class);
    }

    /**
     * L'octroi d'ESSAI du candidat — celui que l'inscription lui a posé.
     *
     * Reconnu par son ORIGINE et non par « celui qui porte une enveloppe » :
     * plusieurs tests en ajoutent une seconde, et c'est précisément leur sujet.
     */
    private function essai(): AccessGrantRecord
    {
        return AccessGrantRecord::query()
            ->where('user_id', $this->candidat->id)
            ->where('capability', AccessGrant::QUESTIONS_ANSWER)
            ->where('origin', 'account_level')
            ->sole();
    }

    private function reliquat(): int
    {
        return $this->service()->reliquat($this->essai()->fresh());
    }

    private function peuplerLaBanque(int $parSousDomaine): void
    {
        $transitions = app(QuestionTransitionService::class);

        foreach (CompetencyNode::where('exam_id', $this->epreuve->id)->where('depth', 1)->get() as $noeud) {
            $remediation = Remediation::firstOrCreate(
                ['competency_node_id' => $noeud->id, 'locale' => 'fr'],
                ['title' => "Remédiation {$noeud->code}", 'content' => 'Contenu.',
                    'estimated_minutes' => 8, 'status' => 'published'],
            );

            for ($i = 1; $i <= $parSousDomaine; $i++) {
                $question = Question::create([
                    'exam_id' => $this->epreuve->id,
                    'competency_node_id' => $noeud->id,
                    'locale' => 'fr',
                    'sibling_group' => (string) Str::uuid7(),
                    'stem' => "Question {$i} — {$noeud->code}",
                    'explanation' => 'Justification.',
                    'remediation_id' => $remediation->id,
                    'author_id' => $this->utilisateur("auteur-3b-{$noeud->code}-{$i}@naja7i.ma")->id,
                ]);

                foreach ([
                    ['A', false, 'A est fausse.', 'confusion_notions'],
                    ['B', true, 'B est juste.', null],
                    ['C', false, 'C est fausse.', 'lecture_enonce'],
                    ['D', false, 'D est fausse.', 'connaissance_absente'],
                    ['Aucune des propositions précédentes', false, 'Fausse.', 'indetermine'],
                ] as $p => [$c, $juste, $justif, $cause]) {
                    QuestionOption::create([
                        'question_id' => $question->id, 'position' => $p + 1,
                        'content' => $c, 'is_correct' => $juste, 'rationale' => $justif, 'cause' => $cause,
                    ]);
                }

                $question->contentSources()->attach($this->source->id, ['verification' => 'verified']);

                $transitions->submitForReview($question);
                $transitions->markReviewed($question, $this->relecteurDeControle());
                $transitions->validate($question, $this->valideur);
                $transitions->publish($question, forDiagnostic: true);
            }
        }
    }

    private function ouvrirDiagnostic(int $total = 10, ?string $cle = null): Attempt
    {
        return app(AttemptService::class)->startDiagnostic(
            $this->candidat, $this->epreuve, 'fr', $cle ?? (string) Str::uuid7(), $total,
        );
    }

    // ═══ S-09 — le débit a lieu au SERVICE, et une seule fois ══════════════

    public function test_s09_une_serie_de_dix_debite_dix_et_rien_d_autre_ne_bouge(): void
    {
        $this->assertSame(40, $this->reliquat());

        $attempt = $this->ouvrirDiagnostic(10);

        $this->assertSame(30, $this->reliquat(), 'Dix items servis, dix unités.');
        $this->assertSame(10, QuestionConsumption::where('attempt_id', $attempt->id)->count());

        // ── Rechargement : lire une tentative ne sert rien de neuf.
        $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$attempt->uuid}")->assertOk();
        $this->assertSame(30, $this->reliquat());

        // ── Second appareil : la même tentative, reprise. Toujours rien.
        $this->ouvrirDiagnostic(10);
        $this->assertSame(30, $this->reliquat(), 'Reprendre une série ouverte ne la refacture pas.');

        // ── Les réponses : une réponse NE CONSOMME RIEN, le débit a eu lieu
        //    à la mise à disposition. C'est la décision Q-08.
        foreach ($attempt->items()->with('question.options')->get() as $item) {
            $this->actingAs($this->candidat)->putJson(
                "/api/v1/me/attempts/{$attempt->uuid}/items/{$item->uuid}",
                ['option_uuid' => $item->question->options->firstWhere('is_correct', false)->uuid,
                    'confidence' => 'hesitant'],
            )->assertOk();
        }
        $this->assertSame(30, $this->reliquat());

        // ── LA FILE HORS LIGNE REJOUÉE — piège P3, rendu sans objet par Q-08.
        //    Dix réponses rejouées à l'identique : le compteur ne bouge pas.
        foreach ($attempt->items()->with('question.options')->get() as $item) {
            $this->actingAs($this->candidat)->putJson(
                "/api/v1/me/attempts/{$attempt->uuid}/items/{$item->uuid}",
                ['option_uuid' => $item->question->options->firstWhere('is_correct', false)->uuid,
                    'confidence' => 'hesitant'],
            )->assertOk();
        }
        $this->assertSame(30, $this->reliquat(), 'Une file rejouée rejoue des RÉPONSES, qui ne consomment rien.');

        // ── Soumission puis correction : lecture, jamais service.
        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/attempts/{$attempt->uuid}/submit")->assertOk();
        $this->assertSame(30, $this->reliquat());

        $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$attempt->uuid}/correction")->assertOk();
        $this->assertSame(30, $this->reliquat(), '30 partout — c'."'".'est tout le scénario S-09.');
    }

    public function test_le_rejeu_d_une_meme_ouverture_ne_pose_qu_une_ligne_par_item(): void
    {
        $cle = (string) Str::uuid7();

        $premier = $this->ouvrirDiagnostic(10, $cle);
        $second = $this->ouvrirDiagnostic(10, $cle);

        $this->assertSame($premier->id, $second->id);
        $this->assertSame(10, QuestionConsumption::where('attempt_id', $premier->id)->count());
        $this->assertSame(30, $this->reliquat());
    }

    public function test_le_debit_rejoue_est_absorbe_par_la_base_et_non_par_un_if(): void
    {
        /*
         * LE REJEU PAR LA CLÉ D'IDEMPOTENCE NE PROUVE PAS L'UNICITÉ.
         *
         * Le test précédent passe parce que `startDiagnostic` rend la tentative
         * existante AVANT d'arriver au débit : c'est la garde d'idempotence qui
         * l'absorbe, pas la contrainte. Elle est la bonne première défense, et
         * elle ne dit rien de la seconde.
         *
         * Ici on appelle donc le débit deux fois de suite, exactement comme le
         * ferait un futur chemin d'ouverture qui n'aurait pas la même garde —
         * une commande, un job, un import. Rien dans le service ne le refuse :
         * c'est `question_consumptions_unique_service` qui tranche, et c'est
         * tout l'objet du pas 1.
         */
        $attempt = $this->ouvrirDiagnostic(10);
        $items = $attempt->items()->get();

        $this->service()->debiter($this->candidat, $attempt, $items, $this->essai());
        $this->service()->debiter($this->candidat, $attempt, $items, $this->essai());

        $this->assertSame(10, QuestionConsumption::where('attempt_id', $attempt->id)->count());
        $this->assertSame(30, $this->reliquat(), 'Un service se contourne, une contrainte non.');
    }

    // ═══ Le coût annoncé — pas 4 ═══════════════════════════════════════════

    public function test_le_cout_est_annonce_avec_le_reliquat_courant(): void
    {
        $meta = $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/diagnostics/{$this->epreuve->code}", ['total' => 10])
            ->assertCreated()
            ->json('meta.envelope');

        $this->assertSame(10, $meta['cost']);
        $this->assertSame(30, $meta['remaining']);
        $this->assertFalse($meta['unlimited']);
        $this->assertStringContainsString('10', $meta['notice']);
        $this->assertStringContainsString('40', $meta['notice'], 'Le coût se dit SUR le reliquat d’avant.');
    }

    public function test_le_reliquat_courant_se_lit_sur_l_ecran_d_abonnement_avant_le_geste(): void
    {
        $avant = $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/subscription')->assertOk()->json('data.quotas.0');

        $this->assertSame(40, $avant['granted']);
        $this->assertSame(40, $avant['remaining']);

        $this->ouvrirDiagnostic(10);

        $apres = $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/subscription')->assertOk()->json('data.quotas.0');

        $this->assertSame(40, $apres['granted'], 'L’enveloppe accordée ne bouge jamais.');
        $this->assertSame(30, $apres['remaining'], 'Le reliquat, lui, est dérivé et il descend.');
    }

    public function test_un_forfait_sans_enveloppe_annonce_qu_il_ne_decompte_pas(): void
    {
        $autre = $this->utilisateur('illimite-3b@naja7i.ma');
        $autre->markEmailAsVerified();
        $autre->grantCandidateRole();
        $this->ouvrirLesDroits($autre->fresh(), AccessGrant::QUESTIONS_ANSWER);

        $meta = $this->actingAs($autre)
            ->postJson("/api/v1/me/diagnostics/{$this->epreuve->code}", ['total' => 10])
            ->assertCreated()
            ->json('meta.envelope');

        $this->assertTrue($meta['unlimited']);
        $this->assertNull($meta['remaining'], 'Un nombre ici ferait croire à une limite.');
    }

    // ═══ La série se compose AU RELIQUAT, elle ne se refuse pas ════════════

    public function test_une_serie_de_dix_demandee_sur_un_reliquat_de_deux_en_compose_deux(): void
    {
        $this->consommer(38);
        $this->assertSame(2, $this->reliquat());

        $reponse = $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/diagnostics/{$this->epreuve->code}", ['total' => 10])
            ->assertCreated();

        $this->assertCount(2, $reponse->json('data.items'), 'Refuser ferait perdre deux unités payées.');
        $this->assertSame(2, $reponse->json('meta.envelope.cost'));
        $this->assertSame(0, $reponse->json('meta.envelope.remaining'));
        $this->assertSame(0, $this->reliquat());
    }

    public function test_a_zero_le_refus_est_nomme_et_distinct_du_mur(): void
    {
        $this->consommer(40);

        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/diagnostics/{$this->epreuve->code}", ['total' => 10])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ENVELOPPE_EPUISEE')
            ->assertJsonPath('error.details.remaining', 0);
    }

    public function test_sans_droit_couvrant_c_est_le_mur_et_pas_l_enveloppe(): void
    {
        AccessGrantRecord::where('user_id', $this->candidat->id)
            ->update(['starts_at' => now()->subMonth(), 'ends_at' => now()->subMinute()]);

        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/diagnostics/{$this->epreuve->code}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CAPABILITY_REQUIRED')
            ->assertJsonPath('error.details.capability', AccessGrant::QUESTIONS_ANSWER);

        /* ET LE CHAMP DISPARAÎT DU RENDU : l'écran d'abonnement ne porte plus
         * d'enveloppe du tout, pas une enveloppe à zéro. */
        $this->assertSame(
            [],
            $this->actingAs($this->candidat)->getJson('/api/v1/me/subscription')->json('data.quotas'),
        );
    }

    // ═══ S-01 — l'illimité gagne, et le reliquat dormant survit ════════════

    public function test_s01_un_forfait_sans_quota_rend_la_consommation_libre_et_le_reliquat_dort(): void
    {
        $this->consommer(33);
        $this->assertSame(7, $this->reliquat());

        /* Le forfait payant : `questions.answer` SANS profil de quota. */
        $forfait = AccessGrantRecord::create([
            'user_id' => $this->candidat->id,
            'capability' => AccessGrant::QUESTIONS_ANSWER,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addMonth(),
            'origin' => 'purchase',
        ]);

        $attempt = $this->ouvrirDiagnostic(10);

        $this->assertSame(
            7, $this->reliquat(),
            'Le reliquat gratuit DORT : ni débité, ni remis à 40, ni vidé.',
        );
        $this->assertSame(
            10,
            QuestionConsumption::where('attempt_id', $attempt->id)->whereNull('access_grant_id')->count(),
            'Une consommation libre laisse une ligne SANS enveloppe : c’est ce qui rend le rejeu idempotent.',
        );

        // ── À l'expiration, la reprise se fait au reliquat, pas à 40 ni à 0.
        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/attempts/{$attempt->uuid}/submit")->assertOk();

        $forfait->forceFill(['ends_at' => now()->subSecond()])->save();

        $this->assertSame(7, $this->reliquat(), 'Reprise à 7 — ni 40, ni 0.');

        $suivante = $this->ouvrirDiagnostic(5);
        $this->assertSame(2, $this->reliquat());
        $this->assertSame(
            5,
            QuestionConsumption::where('attempt_id', $suivante->id)
                ->where('access_grant_id', $this->essai()->id)->count(),
        );
    }

    // ═══ Quelle enveloppe, quand il y en a deux ════════════════════════════

    public function test_c_est_l_enveloppe_qui_expire_le_plus_tot_qui_se_debite(): void
    {
        /* Une seconde enveloppe, DATÉE : elle expire avant l'essai sans terme,
         * qui compte pour l'infini. On consomme d'abord ce qui va se perdre. */
        $datee = AccessGrantRecord::create([
            'user_id' => $this->candidat->id,
            'capability' => AccessGrant::QUESTIONS_ANSWER,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addWeek(),
            'origin' => 'purchase',
            'quota_unit' => 'questions',
            'quota_periodicity' => QuotaPeriodicity::CUMULATIVE_GRANT->value,
            'quota_value' => 12,
        ]);

        $attempt = $this->ouvrirDiagnostic(10);

        $this->assertSame(2, $this->service()->reliquat($datee->fresh()));
        $this->assertSame(40, $this->reliquat(), 'L’enveloppe sans terme n’a pas bougé.');
        $this->assertSame(
            10,
            QuestionConsumption::where('attempt_id', $attempt->id)
                ->where('access_grant_id', $datee->id)->count(),
        );
    }

    public function test_deux_enveloppes_sur_des_portees_disjointes_ne_s_additionnent_jamais(): void
    {
        $ailleurs = Audience::create([
            'code' => 'lycee', 'name_fr' => 'Lycée', 'name_ar' => 'الثانوي', 'position' => 20,
        ]);

        /* Une enveloppe qui ne couvre PAS cette épreuve : elle ne se débite
         * pas, et elle ne s'ajoute pas non plus au reliquat annoncé. */
        $horsPortee = AccessGrantRecord::create([
            'user_id' => $this->candidat->id,
            'capability' => AccessGrant::QUESTIONS_ANSWER,
            'scope_type' => AccessGrantRecord::SCOPE_AUDIENCE,
            'scope_uuid' => $ailleurs->uuid,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'origin' => 'purchase',
            'quota_unit' => 'questions',
            'quota_periodicity' => QuotaPeriodicity::CUMULATIVE_GRANT->value,
            'quota_value' => 5,
        ]);

        $this->assertSame(
            40,
            $this->service()->plafond($this->candidat, $this->epreuve),
            '40, jamais 45 : deux portées disjointes ne s’additionnent pas.',
        );

        $this->ouvrirDiagnostic(10);

        $this->assertSame(30, $this->reliquat());
        $this->assertSame(5, $this->service()->reliquat($horsPortee->fresh()), 'Intacte.');
    }

    // ═══ Ce qui ne consomme rien — Q-16 ════════════════════════════════════

    public function test_un_miroir_et_une_seance_memoire_ne_debitent_aucune_unite(): void
    {
        $this->ouvrirLesDroits($this->candidat, AccessGrant::MEMORY_SESSIONS);

        $attempt = $this->ouvrirDiagnostic(10);
        $this->assertSame(30, $this->reliquat());

        foreach ($attempt->items()->with('question.options')->get() as $item) {
            $this->actingAs($this->candidat)->putJson(
                "/api/v1/me/attempts/{$attempt->uuid}/items/{$item->uuid}",
                ['option_uuid' => $item->question->options->firstWhere('is_correct', false)->uuid,
                    'confidence' => 'hesitant'],
            );
        }
        $this->actingAs($this->candidat)->postJson("/api/v1/me/attempts/{$attempt->uuid}/submit");
        $this->actingAs($this->candidat)->getJson("/api/v1/me/attempts/{$attempt->uuid}/correction");

        $premier = $attempt->items()->first();

        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/mirrors/{$premier->uuid}")
            ->assertCreated();

        $this->assertSame(30, $this->reliquat(), 'Un miroir est payé en amont : la question l’était déjà.');

        /* Le calendrier place le premier rendez-vous à demain ; on le ramène à
         * hier pour que la séance ait quelque chose à servir. C'est la date
         * qu'on déplace, pas le mécanisme. */
        ReviewSchedule::where('user_id', $this->candidat->id)
            ->update(['due_on' => now()->subDay()->toDateString()]);

        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/memory/{$this->epreuve->code}/session")
            ->assertCreated();

        $this->assertSame(30, $this->reliquat(), 'Une séance mémoire ne débite rien non plus (Q-16).');
    }

    // ═══ La fenêtre que le code ne sait pas compter ════════════════════════

    public function test_une_periodicite_non_implementee_refuse_en_la_nommant(): void
    {
        $droit = $this->essai();
        $droit->setRawAttributes(
            array_merge($droit->getAttributes(), ['quota_periodicity' => 'mensuelle_glissante']),
            sync: true,
        );

        try {
            $this->service()->assertFenetreImplementee($droit);
            $this->fail('Compter faux est pire que refuser.');
        } catch (PeriodiciteNonImplementee $e) {
            $this->assertSame('mensuelle_glissante', $e->fenetre);
        }
    }

    public function test_le_code_couvre_toutes_les_fenetres_que_l_enum_declare(): void
    {
        /* Le jour où une seconde fenêtre entre à l'énumération sans que le
         * compteur sache la traiter, c'est ce test qui le dit — avant qu'un
         * candidat ne lise un reliquat faux. */
        $this->assertSame(
            [EnveloppeDeQuestions::FENETRE_IMPLEMENTEE],
            QuotaPeriodicity::cases(),
        );
    }

    /**
     * Consomme `$combien` unités PAR LE CHEMIN RÉEL — séries ouvertes et
     * soumises l'une après l'autre.
     *
     * Poser les lignes à la main aurait été plus court et aurait mesuré le
     * vide : c'est le service qu'on veut voir débiter, pas la table qu'on veut
     * voir se remplir.
     */
    private function consommer(int $combien): void
    {
        while ($combien > 0) {
            $lot = min(10, $combien);
            $attempt = $this->ouvrirDiagnostic($lot);

            $this->actingAs($this->candidat)
                ->postJson("/api/v1/me/attempts/{$attempt->uuid}/submit")->assertOk();

            $combien -= $lot;
        }
    }
}
