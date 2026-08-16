<?php

namespace Tests\Feature\BackOffice;

use App\Filament\Pages\Couverture;
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
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lot A4 — la couverture, en accueil du panneau.
 *
 * CE QUE CES TESTS DÉFENDENT N'EST PAS LE CALCUL. `CouvertureBanque` est
 * éprouvée depuis le PAS-22 et n'est pas retestée ici. Ce qui se joue, c'est
 * qu'un rédacteur qui ouvre `/admin` tombe sur ce qu'il y a à écrire, dans
 * l'ordre de la demande — et que cet ordre soit celui du service, pas celui de
 * la base de données.
 */
class PanneauCouvertureTest extends TestCase
{
    use RefreshDatabase;

    private Exam $epreuve;

    private CompetencyNode $noeud;

    private Source $source;

    private User $redacteur;

    private User $valideur;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        Filament::setCurrentPanel('admin');

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();
        $this->source = Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail();

        $this->redacteur = $this->membre('redacteur@naja7i.ma', 'auteur');
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

    /** Un candidat qui attend ce couple : c'est LUI qui fait exister le trou. */
    private function attendre(string $cause, int $combien): void
    {
        for ($i = 1; $i <= $combien; $i++) {
            $candidat = $this->membre("candidat-{$cause}-{$i}@naja7i.ma", null);
            $candidat->grantCandidateRole();

            ReviewSchedule::create([
                'user_id' => $candidat->id,
                'exam_id' => $this->epreuve->id,
                'competency_node_id' => $this->noeud->id,
                'cause' => $cause,
                'palier' => 1,
                'due_on' => now()->toDateString(),
            ]);
        }
    }

    /** Questions publiées et éligibles au diagnostic, tendant CE piège. */
    private function peupler(string $cause, int $combien, string $locale = 'fr'): void
    {
        $remediation = Remediation::firstOrCreate(
            ['competency_node_id' => $this->noeud->id, 'locale' => $locale],
            ['title' => 'R', 'content' => 'x', 'estimated_minutes' => 5, 'status' => 'published'],
        );

        $transitions = app(QuestionTransitionService::class);

        for ($i = 1; $i <= $combien; $i++) {
            $question = Question::create([
                'exam_id' => $this->epreuve->id,
                'competency_node_id' => $this->noeud->id,
                'locale' => $locale,
                'sibling_group' => (string) Str::uuid7(),
                'stem' => "Énoncé {$cause} {$locale} {$i}",
                'explanation' => 'Justification.',
                'remediation_id' => $remediation->id,
                'author_id' => $this->redacteur->id,
            ]);

            /* Quatre options : `QuestionIntegrityChecker` les exige pour un
             * QCM à réponse unique, et la publication passe par lui. */
            $remplissage = array_values(array_diff(
                ['lecture_enonce', 'connaissance_absente', 'indetermine'],
                [$cause]
            ));

            foreach ([
                ['A', false, $cause],
                ['B', true, null],
                ['C', false, $remplissage[0]],
                ['D', false, $remplissage[1]],
                ['Aucune des propositions précédentes', false, 'indetermine'],
            ] as $p => [$c, $juste, $c2]) {
                QuestionOption::create([
                    'question_id' => $question->id, 'position' => $p + 1,
                    'content' => $c, 'is_correct' => $juste, 'rationale' => 'r', 'cause' => $c2,
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
     * La page vue par un rédacteur, sur l'épreuve du montage.
     *
     * Le filtre est posé explicitement plutôt que laissé à sa valeur par
     * défaut : le catalogue de test compte plusieurs épreuves publiées, et
     * s'appuyer sur celle qui sort première d'un `orderBy` ferait dépendre le
     * test d'un libellé.
     */
    private function page(): Testable
    {
        return Livewire::actingAs($this->redacteur)
            ->test(Couverture::class)
            ->filterTable('exam', $this->epreuve->id);
    }

    // --- L'accueil -------------------------------------------------------------

    public function test_la_racine_du_panneau_sert_la_couverture(): void
    {
        $reponse = $this->actingAs($this->redacteur)->get('/admin');

        $reponse->assertOk();
        $reponse->assertSee('Ce qui manque à la banque');
    }

    public function test_un_candidat_n_atteint_pas_la_couverture(): void
    {
        $candidat = $this->membre('candidat-seul@naja7i.ma', null);
        $candidat->grantCandidateRole();

        /* Le plan de rédaction nomme les causes d'erreur, qui sont un champ
         * payant côté candidat (`CorrectionResource`). Il n'a pas à sortir du
         * back-office. */
        $this->actingAs($candidat);
        $this->assertFalse(Couverture::canAccess());

        $this->actingAs($this->redacteur);
        $this->assertTrue(Couverture::canAccess());
    }

    // --- L'ordre ---------------------------------------------------------------

    /**
     * L'ORDRE EST CELUI DE LA DEMANDE, ET IL SE VOIT.
     *
     * Deux candidats attendent `confusion_notions`, un seul attend `calcul`.
     * La page doit les rendre dans cet ordre — pas dans celui où les
     * rendez-vous ont été créés, qui est ici l'inverse.
     */
    public function test_les_couples_sont_ordonnes_par_candidats_en_attente(): void
    {
        $this->attendre('calcul', 1);
        $this->attendre('confusion_notions', 2);

        $this->page()->assertCanSeeTableRecords([
            'SE-PSY-DEV::confusion_notions',
            'SE-PSY-DEV::calcul',
        ], inOrder: true);
    }

    /**
     * ON PART DE LA DEMANDE, PAS DU CATALOGUE.
     *
     * Aucune question ne tend le piège `piege_formulation` sur cette
     * compétence, et pourtant il n'a rien à faire dans la liste : personne ne
     * l'attend. Sans cette garantie, la page listerait chaque compétence
     * croisée avec les huit causes et ne se lirait plus.
     */
    public function test_un_couple_que_personne_n_attend_n_est_pas_un_trou(): void
    {
        $this->attendre('calcul', 1);

        $this->page()
            ->assertCanSeeTableRecords(['SE-PSY-DEV::calcul'])
            ->assertCanNotSeeTableRecords(['SE-PSY-DEV::piege_formulation']);
    }

    /**
     * LA COUVERTURE EST PAR LANGUE, et l'écran doit le montrer.
     *
     * Deux sœurs en français, aucune en arabe : le couple reste un trou, mais
     * un trou dont une moitié seulement est urgente. Une sévérité unique
     * confondrait les deux travaux de rédaction.
     */
    public function test_les_deux_langues_sont_comptees_separement(): void
    {
        $this->attendre('calcul', 1);
        $this->peupler('calcul', 2, 'fr');

        $composant = $this->page();

        $ligne = collect($composant->instance()->getTableRecords())
            ->firstWhere('__key', 'SE-PSY-DEV::calcul');

        $this->assertNotNull($ligne, 'Couvert en français, le couple reste un trou en arabe.');
        $this->assertSame('covered', $ligne['coverage']['fr']['severity']);
        $this->assertSame(2, $ligne['coverage']['fr']['published_questions']);
        $this->assertSame('none', $ligne['coverage']['ar']['severity']);

        /* Rendu, pas seulement calculé : les deux comptes apparaissent à
         * l'écran avec leur seuil. */
        $composant->assertSee('2 / 2')->assertSee('0 / 2');
    }

    public function test_la_page_est_vide_quand_la_banque_couvre_tout(): void
    {
        $this->attendre('calcul', 1);
        $this->peupler('calcul', 2, 'fr');
        $this->peupler('calcul', 2, 'ar');

        $this->page()
            ->assertCanNotSeeTableRecords(['SE-PSY-DEV::calcul'])
            ->assertSee('Aucun trou');
    }
}
