<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Models\AccessGrantRecord;
use App\Models\Attempt;
use App\Models\CauseAcquisition;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\MasteryScore;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AttemptService;
use App\Services\OffreGratuiteService;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\OuvreLesDroits;
use Tests\TestCase;

/**
 * S-19 — l'état `epuise`, et ce que l'expiration ne détruit pas.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * L'EXPIRATION FERME LA RESTITUTION, JAMAIS LE CALCUL NI LE PASSÉ
 *
 * C'est le principe énoncé une fois et appliqué partout. Un compte épuisé
 * garde SON PASSÉ, figé : ses tentatives, ses corrections déjà obtenues, ses
 * causes acquises — une cause payée ne se reverrouille jamais (PAS-19) — ses
 * rapports d'examens blancs déjà produits, et sa carte de maîtrise au niveau
 * que son dernier droit lui rendait.
 *
 * Ce qui cesse, c'est la PRODUCTION de neuf : ni série, ni examen blanc, ni
 * ordonnance, ni séance mémoire. Le service d'item — donc le diagnostic — se
 * gardera au lot 3B, avec l'enveloppe ; il n'est pas anticipé ici.
 *
 * ET LE CALCUL NE S'ARRÊTE PAS. `MasteryScore` est une TABLE, pas une vue :
 * arrêter `MasteryCalculator` à l'expiration laisserait des scores périmés
 * présentés comme courants — un chiffre faux, ce que le produit refuse
 * partout. Le coût est nul, le calcul est déjà déclenché par la soumission.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ET IL Y A UNE SORTIE
 *
 * Un compte épuisé n'est pas un compte cassé : il lui manque une décision, et
 * l'écran la nomme. « Mon dossier », l'historique et le catalogue des offres
 * restent ouverts — c'est par là qu'on repart. Jamais un retour à l'essai
 * (ADR-0033).
 */
class ScenarioS19Test extends TestCase
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
        $this->valideur = $this->utilisateur('valideur-s19@naja7i.ma');

        $this->candidat = $this->utilisateur('candidat-s19@naja7i.ma');
        $this->candidat->markEmailAsVerified();
        $this->candidat->grantCandidateRole();
        $this->candidat = $this->candidat->fresh();
        app(OffreGratuiteService::class)->attribuer($this->candidat);
        $this->candidat = $this->candidat->fresh();
    }

    private function utilisateur(string $email): User
    {
        return User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
    }

    /** Un forfait acheté, encore courant — les huit gestes du palier complet. */
    private function forfaitCourant(): void
    {
        $this->ouvrirLesDroits(
            $this->candidat,
            AccessGrant::CAUSE_REVEAL,
            AccessGrant::SERIES_TARGETED,
            AccessGrant::SIMULATOR_FULL,
            AccessGrant::MASTERY_DETAIL,
            AccessGrant::REMEDIATION_PLAN,
            AccessGrant::MEMORY_SESSIONS,
        );
    }

    /**
     * L'échéance passe — sans horloge figée, sans toucher au calendrier.
     *
     * On ramène la fin des octrois achetés dans le passé plutôt que de voyager
     * dans le temps : `travel()` figerait l'horloge pour toute la requête
     * suivante, et le `tearDown` du dépôt refuse qu'un test la laisse figée.
     */
    private function faireExpirerLeForfait(): void
    {
        AccessGrantRecord::where('user_id', $this->candidat->id)
            ->where('origin', 'purchase')
            ->update(['starts_at' => now()->subMonth(), 'ends_at' => now()->subMinute()]);
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
                    'author_id' => $this->utilisateur("auteur-s19-{$noeud->code}-{$i}@naja7i.ma")->id,
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

    /** Ouvre un diagnostic, répond FAUX partout, soumet. */
    private function passerUnDiagnostic(int $questions = 10): Attempt
    {
        $attempt = app(AttemptService::class)->startDiagnostic(
            $this->candidat, $this->epreuve, 'fr', (string) Str::uuid7(), $questions,
        );

        foreach ($attempt->items()->with('question.options')->get() as $item) {
            $fausse = $item->question->options->firstWhere('is_correct', false);

            $this->actingAs($this->candidat)->putJson(
                "/api/v1/me/attempts/{$attempt->uuid}/items/{$item->uuid}",
                ['option_uuid' => $fausse->uuid, 'confidence' => 'hesitant'],
            )->assertOk();
        }

        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/attempts/{$attempt->uuid}/submit")
            ->assertOk();

        return $attempt->fresh();
    }

    // ═══ L'expiration en cours de session ══════════════════════════════════

    public function test_l_expiration_pendant_une_session_ouverte_retombe_au_palier_courant(): void
    {
        $this->forfaitCourant();

        $racine = CompetencyNode::where('exam_id', $this->epreuve->id)
            ->whereNull('parent_id')->orderBy('position')->firstOrFail();
        $chapitre = CompetencyNode::where('exam_id', $this->epreuve->id)
            ->where('depth', 1)->orderBy('position')->firstOrFail();

        foreach ([$racine, $chapitre] as $noeud) {
            MasteryScore::create([
                'user_id' => $this->candidat->id, 'exam_id' => $this->epreuve->id,
                'competency_node_id' => $noeud->id, 'score' => 55.0, 'evidence' => 'sufficient',
                'answered_count' => 11, 'correct_count' => 6, 'skipped_count' => 0,
                'lucky_guess_count' => 0, 'confident_error_count' => 1, 'computed_at' => now(),
            ]);
        }

        $this->assertCount(2, $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/mastery/{$this->epreuve->code}")->assertOk()->json('data'));

        $this->faireExpirerLeForfait();

        /* LA REQUÊTE SUIVANTE, SANS ERREUR NI PAGE BLANCHE. Le droit est relu à
         * l'usage, jamais mis en cache dans la session : c'est la promesse de
         * `DatabaseAccessGrant`, et l'inverse ferait durer un abonnement expiré
         * jusqu'à la déconnexion. */
        $apres = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/mastery/{$this->epreuve->code}")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $apres, 'La carte retombe au niveau racine, elle ne disparaît pas.');
        $this->assertSame($racine->code, $apres[0]['node_code']);
    }

    public function test_apres_expiration_les_scores_continuent_d_etre_recalcules(): void
    {
        $this->peuplerLaBanque(6);
        $this->forfaitCourant();

        $this->passerUnDiagnostic(5);
        $avant = MasteryScore::where('user_id', $this->candidat->id)->sum('answered_count');
        $this->assertGreaterThan(0, $avant);

        $this->faireExpirerLeForfait();

        $this->passerUnDiagnostic(5);
        $apres = MasteryScore::where('user_id', $this->candidat->id)->sum('answered_count');

        $this->assertGreaterThan(
            $avant,
            $apres,
            'Un score périmé présenté comme courant serait un chiffre faux : le calcul ne s’arrête jamais.',
        );
    }

    // ═══ Ce que le passé garde ═════════════════════════════════════════════

    public function test_apres_expiration_un_rapport_d_examen_blanc_deja_produit_reste_lisible(): void
    {
        $this->peuplerLaBanque(6);
        $this->forfaitCourant();

        $simulation = app(AttemptService::class)->startSimulation(
            $this->candidat, $this->epreuve, 'fr', (string) Str::uuid7(), 12,
        )['attempt'];

        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/attempts/{$simulation->uuid}/submit")
            ->assertOk();

        $this->faireExpirerLeForfait();

        /* Un livrable acquis. Le retirer serait reprendre ce qui a été payé —
         * et le candidat qui a composé deux heures ne comprendrait pas. */
        $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/simulations/{$simulation->uuid}/report")
            ->assertOk()
            ->assertJsonStructure(['data']);

        /* Mais aucun NEUF : ouvrir un second examen blanc est refusé. */
        $this->actingAs($this->candidat)
            ->postJson("/api/v1/me/simulations/{$this->epreuve->code}")
            ->assertStatus(403)
            ->assertJsonPath('error.details.capability', AccessGrant::SIMULATOR_FULL);
    }

    public function test_apres_expiration_une_cause_acquise_reste_ouverte(): void
    {
        $this->peuplerLaBanque(6);
        $this->forfaitCourant();

        $attempt = $this->passerUnDiagnostic();

        $avant = collect($this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$attempt->uuid}/correction")->assertOk()->json('data'));

        $ouvertes = $avant->where('cause_locked', false)->pluck('item_uuid');
        $this->assertGreaterThan(2, $ouvertes->count(), 'Le forfait ouvrait toutes les causes.');
        $this->assertGreaterThan(0, CauseAcquisition::where('user_id', $this->candidat->id)->count());

        $this->faireExpirerLeForfait();

        $apres = collect($this->actingAs($this->candidat)
            ->getJson("/api/v1/me/attempts/{$attempt->uuid}/correction")->assertOk()->json('data'));

        /* PAS-19, non-régression : une cause déjà payée ne se reverrouille
         * jamais. Le compteur gratuit n'y est pour rien — c'est l'acquisition
         * qui fait foi, et elle est structurelle. */
        foreach ($ouvertes as $uuid) {
            $ligne = $apres->firstWhere('item_uuid', $uuid);
            $this->assertFalse($ligne['cause_locked'], 'Une cause acquise s’est refermée à l’expiration.');
        }
    }

    // ═══ S-19 — le compte épuisé, et sa sortie ═════════════════════════════

    public function test_un_compte_epuise_garde_son_dossier_son_historique_et_le_catalogue(): void
    {
        $this->peuplerLaBanque(6);
        $this->forfaitCourant();
        $this->passerUnDiagnostic();

        /* L'essai est clos comme il le serait par une conversion : c'est ce qui
         * distingue `epuise` d'`essai` (ADR-0033). */
        /* Tout se ferme : l'essai comme le forfait. C'est ce qui distingue
         * `epuise` d'`essai` — l'essai a été clos par la conversion (ADR-0033),
         * et le forfait qui l'a remplacé est arrivé à son terme. */
        AccessGrantRecord::where('user_id', $this->candidat->id)
            ->update(['starts_at' => now()->subMonth(), 'ends_at' => now()->subMinute()]);

        $etat = $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/subscription')->assertOk()->json('data');

        $this->assertSame('epuise', $etat['etat']);
        $this->assertNotNull($etat['sortie'], 'Un compte épuisé n’est pas cassé : il lui manque une décision.');
        $this->assertSame([], $etat['capabilities']);

        // « Mon dossier »
        $this->actingAs($this->candidat)->getJson('/api/v1/me')->assertOk();

        // L'historique de ses tentatives
        $this->assertNotEmpty(
            $this->actingAs($this->candidat)->getJson('/api/v1/me/attempts')->assertOk()->json('data'),
        );

        // Le catalogue des offres — sa sortie, et elle est même publique
        $this->assertNotEmpty($this->getJson('/api/v1/plans')->assertOk()->json('data'));

        // Ses commandes
        $this->actingAs($this->candidat)->getJson('/api/v1/me/orders')->assertOk();
    }

    public function test_un_compte_epuise_ne_produit_plus_rien_de_neuf(): void
    {
        $this->peuplerLaBanque(6);

        AccessGrantRecord::where('user_id', $this->candidat->id)
            ->update(['starts_at' => now()->subMonth(), 'ends_at' => now()->subMinute()]);

        $noeud = CompetencyNode::where('exam_id', $this->epreuve->id)
            ->where('depth', 1)->orderBy('position')->firstOrFail();

        foreach ([
            "/api/v1/me/training/{$this->epreuve->code}" => AccessGrant::SERIES_TARGETED,
            "/api/v1/me/simulations/{$this->epreuve->code}" => AccessGrant::SIMULATOR_FULL,
            "/api/v1/me/memory/{$this->epreuve->code}/session" => AccessGrant::MEMORY_SESSIONS,
        ] as $chemin => $capacite) {
            $this->actingAs($this->candidat)
                ->postJson($chemin, ['node_uuid' => $noeud->uuid])
                ->assertStatus(403)
                ->assertJsonPath('error.details.capability', $capacite);
        }

        $ordonnance = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/plan/{$this->epreuve->code}")->assertOk();
        $this->assertArrayNotHasKey('data', $ordonnance->json());

        $memoire = $this->actingAs($this->candidat)
            ->getJson("/api/v1/me/memory/{$this->epreuve->code}/due")->assertOk();
        $this->assertArrayNotHasKey('data', $memoire->json());

        $this->assertSame(0, Attempt::where('user_id', $this->candidat->id)
            ->whereIn('kind', ['training', 'simulation', 'review'])->count());
    }
}
