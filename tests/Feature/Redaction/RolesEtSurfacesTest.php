<?php

namespace Tests\Feature\Redaction;

use App\Filament\Resources\Questions\Actions\ActesEditoriaux;
use App\Filament\Resources\Questions\Pages\ListQuestions;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les actes éditoriaux restent gouvernés par leurs permissions et leur état.
 * Le profil expert unifié peut tous les exercer, y compris sur une question
 * qu'il a lui-même rédigée, tandis que chaque identité d'acteur reste tracée.
 */
class RolesEtSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private Exam $epreuve;

    private CompetencyNode $noeud;

    private QuestionTransitionService $transitions;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->noeud = CompetencyNode::where('exam_id', $this->epreuve->id)->where('depth', 1)->firstOrFail();
        $this->transitions = app(QuestionTransitionService::class);
    }

    /** Le service conserve l'acteur sans imposer une seconde personne. */
    public function test_un_expert_peut_relire_sa_propre_question(): void
    {
        $editeur = $this->membre('editeur-solitaire@naja7i.ma', 'expert_pedagogue');
        $question = $this->questionDe($editeur);

        $this->transitions->submitForReview($question);

        $relue = $this->transitions->markReviewed($question->fresh(), $editeur);

        $this->assertSame('reviewed', $relue->status);
        $this->assertSame($editeur->id, $relue->reviewer_id);
    }

    /**
     * Le pendant : un AUTRE compte relit sans obstacle. Sans ce test, refuser
     * toute relecture satisferait le précédent.
     */
    public function test_un_autre_compte_relit_sans_obstacle(): void
    {
        $auteur = $this->membre('auteur-a@naja7i.ma', 'expert_pedagogue');
        $relecteur = $this->membre('relecteur-a@naja7i.ma', 'expert_pedagogue');

        $question = $this->questionDe($auteur);
        $this->transitions->submitForReview($question);

        $relue = $this->transitions->markReviewed($question->fresh(), $relecteur);

        $this->assertSame('reviewed', $relue->status);
        $this->assertSame($relecteur->id, $relue->reviewer_id);
    }

    /** Relecture et validation peuvent être accomplies par le même expert. */
    public function test_un_expert_peut_valider_apres_avoir_relu(): void
    {
        $auteur = $this->membre('auteur-b@naja7i.ma', 'expert_pedagogue');
        $relecteur = $this->membre('relecteur-b@naja7i.ma', 'expert_pedagogue');

        $question = $this->questionDe($auteur);
        $this->transitions->submitForReview($question);
        $this->transitions->markReviewed($question->fresh(), $relecteur);

        $validee = $this->transitions->validate($question->fresh(), $relecteur);

        $this->assertSame('pedagogically_validated', $validee->status);
        $this->assertSame($relecteur->id, $validee->reviewer_id);
        $this->assertSame($relecteur->id, $validee->validator_id);
    }

    /**
     * D-16 — LE RELECTEUR TROUVE SON ACTE SUR UNE SURFACE QU'IL PEUT ATTEINDRE.
     *
     * PREMIÈRE ÉCRITURE FAUSSE, ET IL FAUT LE DIRE. Elle assertait
     * `can('update') || can('view')` — et `view` est vrai pour le relecteur,
     * donc elle passait AVANT toute correction. Elle n'aurait jamais rougi. Le
     * défaut n'était pas dans les permissions, qui étaient justes : il était
     * dans l'HÉBERGEMENT de l'action. Le test doit donc interroger la surface.
     *
     * On demande à la LISTE — la seule page qu'un relecteur peut ouvrir — quelles
     * actions elle porte, et on vérifie que « relire » y est visible pour lui.
     */
    public function test_le_relecteur_trouve_son_acte_sur_la_liste(): void
    {
        $auteur = $this->membre('auteur-c@naja7i.ma', 'expert_pedagogue');
        $relecteur = $this->membre('relecteur-c@naja7i.ma', 'expert_pedagogue');

        $question = $this->questionDe($auteur);
        $this->transitions->submitForReview($question);
        $question = $question->fresh();

        $this->assertTrue(
            $relecteur->can('update', $question),
            'Le profil expert unifié porte aussi la rédaction tant que le contenu n’est pas gelé.'
        );

        $this->assertActeOffertSurLaListe(
            $relecteur,
            $question,
            'relire',
            'Le relecteur ne trouve « Marquer relue » sur AUCUNE surface qu’il peut '
            .'ouvrir. Mesuré en recette : l’acte vivait sur `EditQuestion`, page gardée '
            .'par `questions.create` — une permission qui n’est pas la sienne.'
        );
    }

    /**
     * D-20 — UNE QUESTION PUBLIÉE GARDE SON ACTE DE RETRAIT SUR LA LISTE.
     *
     * Même mécanisme : `retirer` vivait sur une page que le gel du contenu
     * ferme. La policy autorisait le retrait ; aucun écran ne l’offrait.
     */
    public function test_une_question_publiee_garde_son_retrait_sur_la_liste(): void
    {
        $publiee = $this->questionPubliee();
        $valideur = User::where('email', 'valideur-d@naja7i.ma')->firstOrFail();

        $this->assertFalse(
            $valideur->can('update', $publiee),
            'Le contenu publié est gelé : la page d’édition doit rester fermée.'
        );

        $this->assertActeOffertSurLaListe(
            $valideur,
            $publiee,
            'retirer',
            'Une question publiée n’offre « Retirer » sur aucune surface. C’est la seule '
            .'transition que la table autorise depuis cet état, et le rôle porte '
            .'`questions.retire`.'
        );
    }

    /**
     * D-02 — LE PANNEAU S'OUVRE SUR « EST-CE UN MEMBRE DU PERSONNEL », PAS SUR
     * UNE PERMISSION ÉDITORIALE PARTICULIÈRE.
     *
     * `canAccessPanel()` interrogeait `questions.view`. Le rôle `finance`, qui
     * porte `orders.validate`, en est dépourvu : l'opérateur prévu pour valider
     * les commandes ne pouvait pas entrer dans le panneau qui les affiche.
     */
    public function test_un_operateur_commercial_entre_dans_le_panneau(): void
    {
        $finance = $this->membre('finance@naja7i.ma', 'finance');
        $panneau = Filament::getPanel('admin');

        $this->assertTrue(
            $finance->canAccessPanel($panneau),
            'Le rôle `finance` porte `orders.view` et `orders.validate` : le panneau '
            .'qui héberge la file des commandes doit s’ouvrir à lui. Mesuré en '
            .'recette : renvoyé à la page de connexion, parce que l’accès était '
            .'gardé par `questions.view`.'
        );
    }

    /** Un candidat, lui, n'entre pas. Sans ce test, ouvrir à tous passerait. */
    public function test_un_candidat_n_entre_pas_dans_le_panneau(): void
    {
        $candidat = $this->membre('candidat-panneau@naja7i.ma', 'candidat');
        $panneau = Filament::getPanel('admin');

        $this->assertFalse(
            $candidat->canAccessPanel($panneau),
            'Un candidat n’a aucune permission de personnel : le panneau lui reste fermé.'
        );
    }

    /**
     * LA GARANTIE — aucun acte de la chaîne ne retourne vivre sur la seule
     * page d'édition.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * LE RECENSEMENT QUI L'A MOTIVÉE, chiffré
     *
     * Treize actions dans tout le panneau. Recensées une par une, sur deux
     * critères : est-elle gouvernée par une permission qui n'est pas celle de
     * son métier, et est-elle offerte sans vérifier la validité de la
     * transition.
     *
     * SECOND CRITÈRE : ZÉRO. Les treize vérifient l'état — `soumettre` teste
     * `draft`, `revoquer` teste `!== 'revoque'`, `valider` et `refuser` sur les
     * commandes testent `en_attente`, `verifier` teste `! estVerifiee()`, et
     * les autres délèguent à une policy qui teste le statut. Sur ce point le
     * dépôt était sain, et la première rédaction de D-20 était FAUSSE : j'avais
     * lu « Seule transition permise depuis `published` » comme « retirer n'est
     * permis que depuis published », alors que la phrase dit l'inverse — depuis
     * `published`, la seule sortie est `retired`. La table autorise bien le
     * retrait depuis cinq états.
     *
     * PREMIER CRITÈRE : CINQ SUR TREIZE, plus la porte du panneau elle-même.
     * Les cinq actes éditoriaux vivaient sur `EditQuestion`, page gouvernée par
     * `questions.create` et fermée sur contenu gelé :
     *
     *   · `relire`    — CASSÉ en pratique : le rôle `reviseur` n'a pas
     *                   `questions.create`, il ne pouvait rien relire ;
     *   · `retirer`   — CASSÉ en pratique : la page refuse `published`, seul
     *                   état où l'acte compte vraiment ;
     *   · `valider`   — LATENT : ne marchait que parce que le rôle `editeur`
     *                   porte aussi `questions.create`. Un rôle « valideur
     *                   seul » aurait été enfermé dehors ;
     *   · `publier`   — LATENT, même raison ;
     *   · `soumettre` — sans conséquence : son acteur est l'auteur, qui porte
     *                   `questions.create` par définition.
     *
     * Deux défauts vécus, deux défauts en attente d'un rôle qu'on n'avait pas
     * encore créé. C'est la proportion qui compte : les défauts latents ne se
     * révèlent qu'au moment où l'on découpe les rôles plus finement — c'est-à-dire
     * au premier partenaire B2B.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * CE QUE CETTE GARANTIE PEUT ET NE PEUT PAS
     *
     * Elle ne sait pas décider si une permission est « celle du métier » d'une
     * action : ce jugement demande de savoir ce que l'action veut dire. Ce
     * qu'elle tient, c'est que les actes de la chaîne restent offerts sur la
     * LISTE — la seule surface qu'un membre du personnel peut toujours ouvrir.
     * Un acte ajouté demain sur la seule page d'édition fait rougir ce test.
     */
    public function test_tous_les_actes_de_la_chaine_sont_offerts_sur_la_liste(): void
    {
        $noms = collect(ActesEditoriaux::tous())
            ->map(fn ($a) => $a->getName())
            ->all();

        $source = file_get_contents(
            app_path('Filament/Resources/Questions/Tables/QuestionsTable.php')
        );

        $this->assertStringContainsString(
            'ActesEditoriaux::tous()',
            $source,
            'La liste des questions n’offre plus les actes de la chaîne. Ils sont alors '
            .'hébergés sur la seule page d’édition, gouvernée par `questions.create` et '
            .'fermée sur contenu gelé — le relecteur ne peut plus relire, et une question '
            .'publiée ne peut plus être retirée. Actes concernés : '.implode(', ', $noms)
        );

        $this->assertSame(
            ['soumettre', 'relire', 'valider', 'publier', 'retirer'],
            $noms,
            'La composition de la chaîne a changé. Vérifiez que chaque acte nouveau est '
            .'atteignable depuis la liste, et pas seulement depuis la page d’édition.'
        );
    }

    // ─────────────────────────────────────────────────────────── fabrique

    /**
     * Les actes que LA LISTE DU PRODUIT offre sur ce dossier, pour le compte
     * connecté.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * PREMIÈRE ÉCRITURE FAUSSE, ET ELLE EST INSTRUCTIVE
     *
     * Elle appelait `ActesEditoriaux::tous()` et regardait lesquels étaient
     * visibles. Mesuré par mutation : en retirant les actes du tableau, la
     * suite entière restait VERTE — 621 verts, zéro rouge. Le test
     * fabriquait sa propre liste et l'interrogeait ; il ne touchait jamais au
     * câblage du produit. C'est le genre 3 du bestiaire, commis dans le test
     * écrit pour fermer un défaut d'hébergement.
     *
     * On demande donc à la PAGE DE LISTE, celle que Filament rend réellement.
     * Si l'acte n'y est pas branché, l'assertion tombe.
     */
    private function assertActeOffertSurLaListe(User $compte, Question $question, string $acte, string $message): void
    {
        Livewire::actingAs($compte)
            ->test(ListQuestions::class)
            ->assertTableActionVisible($acte, $question->getKey(), $message);
    }

    private function questionPubliee(): Question
    {
        $auteur = $this->membre('auteur-d@naja7i.ma', 'expert_pedagogue');
        $relecteur = $this->membre('relecteur-d@naja7i.ma', 'expert_pedagogue');
        $valideur = $this->membre('valideur-d@naja7i.ma', 'expert_pedagogue');

        $question = $this->questionDe($auteur);
        $this->transitions->submitForReview($question);
        $this->transitions->markReviewed($question->fresh(), $relecteur);
        $this->transitions->validate($question->fresh(), $valideur);

        return $this->transitions->publish($question->fresh());
    }

    private function membre(string $email, string $role): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
            'status' => 'active',
        ]);
        $user->markEmailAsVerified();

        $id = Role::where('code', $role)->whereNull('tenant_id')->value('id');
        $user->memberships()->create(['role_id' => $id]);

        return $user->fresh();
    }

    private function questionDe(User $auteur): Question
    {
        $remediation = Remediation::create([
            'competency_node_id' => $this->noeud->id, 'locale' => 'fr',
            'title' => 'Remédiation '.uniqid(), 'content' => 'Contenu.',
            'estimated_minutes' => 8, 'status' => 'published',
        ]);

        $question = Question::create([
            'exam_id' => $this->epreuve->id,
            'competency_node_id' => $this->noeud->id,
            'locale' => 'fr',
            'sibling_group' => (string) Str::uuid7(),
            'stem' => 'Énoncé de contrôle '.uniqid(),
            'explanation' => 'Justification générale.',
            'remediation_id' => $remediation->id,
            'author_id' => $auteur->id,
        ]);

        /* CINQ options : l'épreuve CRMEF-SE-2025 en déclare cinq, et le contrôle
         * de publication le vérifie. Quatre suffisaient à ma première fixture,
         * et la publication a refusé — la règle fait son travail. */
        foreach ([true, false, false, false, false] as $i => $juste) {
            QuestionOption::create([
                'question_id' => $question->id,
                'content' => 'Option '.$i,
                'is_correct' => $juste,
                'rationale' => $juste ? 'Exacte.' : 'Fausse.',
                'cause' => $juste ? null : 'lecture_enonce',
                'position' => $i,
            ]);
        }

        return $question->fresh();
    }
}
