<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\MasteryScore;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OffreGratuiteService;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\OuvreLesDroits;
use Tests\TestCase;

/**
 * Les murs du palier — lot 3A.9.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE PREMIER LOT QUI RETIRE
 *
 * Tout ce qui précède s'ajoutait sans rien changer pour un candidat. Ici, des
 * routes ouvertes se ferment. Ce fichier tient la forme de cette fermeture,
 * qui est la décision de fond du lot :
 *
 * **Le mur est un CHAMP, pas une route.** Soit une chose est dans le rendu,
 * soit elle n'y est pas. Jamais un bouton grisé, jamais un champ vide « à
 * débloquer », jamais un compteur factice — annoncer « 42 rendez-vous dus » à
 * qui ne peut pas ouvrir de séance construit un cul-de-sac, pas une vitrine.
 *
 * **Sauf pour une ACTION, qui doit se refuser.** Ouvrir une série, un examen
 * blanc ou une séance écrit ; le serveur ne peut pas compter sur le client
 * pour ne pas proposer le geste. Ce refus est un 403 NOMMÉ — la règle « 404,
 * jamais 403 » vise l'énumération de ce qui appartient à AUTRUI, et il n'y a
 * rien à énumérer ici : la fonction est au catalogue, son prix est public.
 *
 * **La maîtrise fait exception, et c'est arbitré** (option B) : elle se rend
 * toujours, à la profondeur que le palier ouvre. Le palier vend la
 * GRANULARITÉ, pas l'existence de la mesure.
 */
class MursDuPalierTest extends TestCase
{
    use OuvreLesDroits, RefreshDatabase;

    private Exam $epreuve;

    private Source $source;

    private User $valideur;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();
        $this->valideur = $this->utilisateur('valideur-murs@naja7i.ma');
    }

    private function utilisateur(string $email): User
    {
        return User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
    }

    /** Un compte d'essai : le palier gratuit, et rien d'autre. */
    private function candidat(string $email = 'essai-murs@naja7i.ma'): User
    {
        $compte = $this->utilisateur($email);
        $compte->markEmailAsVerified();
        $compte->grantCandidateRole();
        $compte = $compte->fresh();

        app(OffreGratuiteService::class)->attribuer($compte);

        return $compte->fresh();
    }

    private function noeudRacine(): CompetencyNode
    {
        return CompetencyNode::where('exam_id', $this->epreuve->id)
            ->whereNull('parent_id')->orderBy('position')->firstOrFail();
    }

    private function noeudProfond(): CompetencyNode
    {
        return CompetencyNode::where('exam_id', $this->epreuve->id)
            ->where('depth', 1)->orderBy('position')->firstOrFail();
    }

    /**
     * Une carte de maîtrise à deux étages : un domaine et un de ses chapitres,
     * tous deux avec assez d'évidence pour être affichables.
     */
    private function semerLaCarte(User $compte): void
    {
        foreach ([$this->noeudRacine(), $this->noeudProfond()] as $noeud) {
            MasteryScore::create([
                'user_id' => $compte->id,
                'exam_id' => $this->epreuve->id,
                'competency_node_id' => $noeud->id,
                'score' => 62.5,
                'evidence' => 'sufficient',
                'answered_count' => 12,
                'correct_count' => 7,
                'skipped_count' => 0,
                'lucky_guess_count' => 1,
                'confident_error_count' => 2,
                'computed_at' => now(),
            ]);
        }
    }

    /** Peuple un sous-domaine de questions publiées, éligibles au diagnostic. */
    private function peupler(CompetencyNode $noeud, int $combien): void
    {
        $remediation = Remediation::firstOrCreate(
            ['competency_node_id' => $noeud->id, 'locale' => 'fr'],
            ['title' => "Remédiation {$noeud->code}", 'content' => 'Contenu.',
                'estimated_minutes' => 8, 'status' => 'published'],
        );

        $transitions = app(QuestionTransitionService::class);

        for ($i = 1; $i <= $combien; $i++) {
            $question = Question::create([
                'exam_id' => $this->epreuve->id,
                'competency_node_id' => $noeud->id,
                'locale' => 'fr',
                'sibling_group' => (string) Str::uuid7(),
                'stem' => "Question {$i} — {$noeud->code}",
                'explanation' => 'Justification.',
                'remediation_id' => $remediation->id,
                'author_id' => $this->utilisateur("auteur-murs-{$noeud->code}-{$i}@naja7i.ma")->id,
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

    // ═══ Le champ absent, jamais grisé ═════════════════════════════════════

    public function test_un_compte_d_essai_ne_recoit_pas_le_champ_de_l_ordonnance(): void
    {
        $reponse = $this->actingAs($this->candidat())
            ->getJson("/api/v1/me/plan/{$this->epreuve->code}")
            ->assertOk();

        $this->assertArrayNotHasKey('data', $reponse->json());
        $this->assertSame(['exam_code' => $this->epreuve->code], $reponse->json('meta'));
    }

    public function test_un_compte_d_essai_ne_recoit_ni_liste_ni_compteur_de_rendez_vous(): void
    {
        $reponse = $this->actingAs($this->candidat())
            ->getJson("/api/v1/me/memory/{$this->epreuve->code}/due")
            ->assertOk();

        $this->assertArrayNotHasKey('data', $reponse->json());

        /* AUCUN COMPTEUR. « 42 dus » sans séance possible est un cul-de-sac :
         * le candidat apprend qu'il a du retard et n'a aucun geste à faire. */
        foreach (['due_total', 'served', 'pending', 'cap', 'next_due_on', 'without_sibling'] as $compteur) {
            $this->assertArrayNotHasKey($compteur, $reponse->json('meta'));
        }
    }

    public function test_aucune_reponse_fermee_ne_porte_de_champ_a_debloquer(): void
    {
        $candidat = $this->candidat();

        foreach ([
            "/api/v1/me/plan/{$this->epreuve->code}",
            "/api/v1/me/memory/{$this->epreuve->code}/due",
            "/api/v1/me/mastery/{$this->epreuve->code}",
        ] as $chemin) {
            $corps = $this->actingAs($candidat)->getJson($chemin)->assertOk()->json();
            $aplati = json_encode($corps, JSON_UNESCAPED_UNICODE);

            /* Ni drapeau de verrouillage, ni invitation cachée, ni compteur
             * factice : ce qui n'est pas ouvert n'est simplement pas là. */
            foreach (['locked', 'disabled', 'upgrade', 'blur', 'hidden', 'teaser'] as $mot) {
                $this->assertStringNotContainsString($mot, $aplati, "« {$mot} » dans {$chemin}.");
            }
        }
    }

    // ═══ La restitution graduée de la maîtrise ═════════════════════════════

    public function test_un_compte_d_essai_voit_les_racines_et_aucun_noeud_plus_profond(): void
    {
        $candidat = $this->candidat();
        $this->semerLaCarte($candidat);

        $rendus = $this->actingAs($candidat)
            ->getJson("/api/v1/me/mastery/{$this->epreuve->code}")
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($rendus, 'La carte se rend : le palier vend la profondeur, pas l’existence.');
        $this->assertSame([0], array_values(array_unique(array_column($rendus, 'depth'))));
        $this->assertNotContains($this->noeudProfond()->code, array_column($rendus, 'node_code'));
    }

    public function test_un_compte_portant_mastery_detail_voit_l_arbre_complet(): void
    {
        $candidat = $this->candidat('detail-murs@naja7i.ma');
        $this->ouvrirLesDroits($candidat, AccessGrant::MASTERY_DETAIL);
        $this->semerLaCarte($candidat);

        $rendus = $this->actingAs($candidat)
            ->getJson("/api/v1/me/mastery/{$this->epreuve->code}")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rendus);
        $this->assertContains($this->noeudProfond()->code, array_column($rendus, 'node_code'));
    }

    // ═══ Les actions refusées, et le refus qui nomme sa clé ════════════════

    public function test_l_ouverture_d_une_serie_ciblee_est_refusee_en_nommant_ce_qui_l_ouvrirait(): void
    {
        $this->actingAs($this->candidat())
            ->postJson("/api/v1/me/training/{$this->epreuve->code}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CAPABILITY_REQUIRED')
            ->assertJsonPath('error.details.capability', AccessGrant::SERIES_TARGETED);
    }

    public function test_l_ouverture_d_un_examen_blanc_est_refusee_avant_meme_la_banque(): void
    {
        /* Le refus précède la durée officielle et la banque : « la durée n'est
         * pas établie » enverrait le candidat chercher un défaut de référentiel
         * là où il n'y a qu'un palier. */
        $this->actingAs($this->candidat())
            ->postJson("/api/v1/me/simulations/{$this->epreuve->code}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CAPABILITY_REQUIRED')
            ->assertJsonPath('error.details.capability', AccessGrant::SIMULATOR_FULL);
    }

    public function test_l_ouverture_d_une_seance_memoire_est_refusee(): void
    {
        $this->actingAs($this->candidat())
            ->postJson("/api/v1/me/memory/{$this->epreuve->code}/session")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CAPABILITY_REQUIRED')
            ->assertJsonPath('error.details.capability', AccessGrant::MEMORY_SESSIONS);
    }

    public function test_le_refus_dit_en_toutes_lettres_quelle_cle_demander(): void
    {
        $message = $this->actingAs($this->candidat())
            ->postJson("/api/v1/me/simulations/{$this->epreuve->code}")
            ->assertStatus(403)
            ->json('error.message');

        /* Le libellé vient du registre bilingue, pas d'un code d'énumération :
         * « simulator.full » n'a jamais rien dit à personne. */
        $this->assertStringNotContainsString(AccessGrant::SIMULATOR_FULL, $message);
        $this->assertStringContainsString('catalogue', $message);
    }

    // ═══ Le palier Préparation : ouvert d'un côté, fermé de l'autre ════════

    public function test_le_palier_preparation_ouvre_l_entrainement_et_ferme_l_ordonnance(): void
    {
        $noeud = $this->noeudProfond();
        $this->peupler($noeud, 12);

        /* La composition arbitrée de « Préparation » (D-CAT), à la lettre. */
        $candidat = $this->candidat('preparation-murs@naja7i.ma');
        $this->ouvrirLesDroits(
            $candidat,
            AccessGrant::CAUSE_REVEAL,
            AccessGrant::SERIES_TARGETED,
            AccessGrant::SIMULATOR_FULL,
        );

        /* LES DEUX DANS LA MÊME SESSION : c'est le test d'acceptation §5 de la
         * matrice. Un palier n'est pas « ouvert » ou « fermé » — il ouvre
         * certaines choses et pas d'autres, au même instant. */
        $this->actingAs($candidat)
            ->postJson("/api/v1/me/training/{$this->epreuve->code}", [
                'node_uuid' => $noeud->uuid, 'total' => 5,
            ])
            ->assertStatus(201);

        $ordonnance = $this->actingAs($candidat)
            ->getJson("/api/v1/me/plan/{$this->epreuve->code}")
            ->assertOk();

        $this->assertArrayNotHasKey('data', $ordonnance->json());

        $memoire = $this->actingAs($candidat)
            ->getJson("/api/v1/me/memory/{$this->epreuve->code}/due")
            ->assertOk();

        $this->assertArrayNotHasKey('data', $memoire->json());
    }

    public function test_le_palier_session_complete_ouvre_l_ordonnance_et_les_rendez_vous(): void
    {
        $candidat = $this->candidat('session-murs@naja7i.ma');
        $this->ouvrirLesDroits(
            $candidat,
            AccessGrant::REMEDIATION_PLAN,
            AccessGrant::MEMORY_SESSIONS,
        );

        $this->actingAs($candidat)
            ->getJson("/api/v1/me/plan/{$this->epreuve->code}")
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['exam_code', 'disclaimer']]);

        $this->actingAs($candidat)
            ->getJson("/api/v1/me/memory/{$this->epreuve->code}/due")
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['due_total', 'next_due_on']]);
    }
}
