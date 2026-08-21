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
use RuntimeException;
use Tests\TestCase;

/**
 * L'ÉCART ENTRE LES RÔLES ET LES SURFACES — la cause unique de quatre défauts.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA RÈGLE, EN TROIS LIGNES
 *
 *   1. Une action est offerte SI ET SEULEMENT SI la permission qui la gouverne
 *      est portée par le compte, ET la transition est valide dans l'état
 *      courant du dossier.
 *   2. Une action n'est jamais hébergée sur une surface dont l'accès est
 *      gouverné par une AUTRE permission que la sienne.
 *   3. Ce que l'écran refuse, le service le refuse aussi. L'écran explique ;
 *      le service protège. Jamais l'inverse, jamais l'un sans l'autre.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE LA RECETTE HUMAINE A MESURÉ, ET QUE CES TESTS FIXENT
 *
 * La règle 2 est celle que nous avions perdue, et elle explique à elle seule
 * trois défauts sur quatre. Les actions `relire`, `valider`, `publier` et
 * `retirer` vivent toutes sur `EditQuestion`. Or l'accès à cette page est
 * gouverné par `QuestionPolicy::update()`, qui exige `questions.create` et
 * refuse tout statut gelé. Conséquences observées sur une instance qui tourne :
 *
 *   · le RELECTEUR, qui porte `questions.review` mais pas `questions.create`,
 *     reçoit 403 sur la page qui héberge le bouton « Marquer relue ». Le rôle
 *     dont c'est le seul métier ne peut relire aucune question ;
 *   · une question PUBLIÉE ne peut plus être retirée : `update()` refuse le
 *     statut `published` — à juste titre, le contenu est gelé — et emporte avec
 *     lui l'action `retirer`, qui est pourtant la seule transition que la table
 *     autorise depuis cet état.
 *
 * La règle 1 est celle qui manquait à `review`. `validate()` refuse déjà son
 * auteur, dans le service comme dans la policy. `review` ne le faisait ni ici
 * ni là : un compte portant `questions.create` ET `questions.review` — c'est le
 * rôle `editeur` livré par le semis — écrivait une question, la soumettait, et
 * la relisait lui-même. Mesuré en base : `author_id = reviewer_id`.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * L'ARBITRAGE SUR LES TROIS RÔLES, ET SON RAISONNEMENT
 *
 * Question posée : `validator_id` peut-il valoir `reviewer_id` ?
 *
 * TRANCHÉ : NON. Trois actes, trois personnes distinctes.
 *
 * Le raisonnement. La chaîne comporte trois actes qui ne mesurent pas la même
 * chose. L'auteur produit. Le relecteur vérifie la FORME — l'énoncé est-il
 * clair, les options sont-elles justifiées, la cause est-elle plausible. Le
 * valideur engage le FOND pédagogique : il déclare que cette question mesure
 * bien la compétence qu'elle prétend mesurer, et c'est cette signature qui
 * fonde la crédibilité de la banque devant un candidat.
 *
 * Si le relecteur valide ce qu'il vient de relire, il ne reste que deux regards
 * là où le dispositif en promet trois — et surtout, le second regard perd son
 * indépendance : on ne conteste pas volontiers ce qu'on vient d'approuver.
 * L'ancrage est un biais documenté, pas une méfiance envers les personnes.
 *
 * LE COÛT EST RÉEL ET ASSUMÉ : une équipe éditoriale ne peut pas publier à
 * moins de trois comptes distincts. C'est un coût d'organisation, pas un coût
 * technique, et c'est exactement la garantie que le document du parcours
 * candidat promet à des tiers. Une promesse publiée qui coûte moins cher que
 * ce qu'elle annonce n'est pas une économie, c'est une fausse déclaration.
 *
 * La règle est donc : `reviewer_id != author_id`, et
 * `validator_id != author_id` ET `validator_id != reviewer_id`.
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

    /**
     * D-17 — LE DÉFAUT LE PLUS GRAVE DE LA RECETTE.
     *
     * Un compte qui porte l'écriture ET la relecture relit son propre travail.
     * Le test le tente PAR LE SERVICE, parce que c'est là que la garantie doit
     * vivre : une garde qui n'existerait que dans Filament laisserait passer
     * l'API d'administration, la console et tout appelant futur.
     */
    public function test_un_auteur_ne_peut_pas_relire_sa_propre_question(): void
    {
        $editeur = $this->membre('editeur-solitaire@naja7i.ma', 'editeur');
        $question = $this->questionDe($editeur);

        $this->transitions->submitForReview($question);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/auteur/i');

        $this->transitions->markReviewed($question->fresh(), $editeur);
    }

    /**
     * Le pendant : un AUTRE compte relit sans obstacle. Sans ce test, refuser
     * toute relecture satisferait le précédent.
     */
    public function test_un_autre_compte_relit_sans_obstacle(): void
    {
        $auteur = $this->membre('auteur-a@naja7i.ma', 'auteur');
        $relecteur = $this->membre('relecteur-a@naja7i.ma', 'reviseur');

        $question = $this->questionDe($auteur);
        $this->transitions->submitForReview($question);

        $relue = $this->transitions->markReviewed($question->fresh(), $relecteur);

        $this->assertSame('reviewed', $relue->status);
        $this->assertSame($relecteur->id, $relue->reviewer_id);
    }

    /**
     * TROIS ACTES, TROIS PERSONNES — le valideur n'est pas non plus le
     * relecteur. L'arbitrage et son raisonnement sont dans le docblock de
     * classe ; ce test le rend opposable.
     */
    public function test_le_valideur_n_est_ni_l_auteur_ni_le_relecteur(): void
    {
        $auteur = $this->membre('auteur-b@naja7i.ma', 'auteur');
        $relecteur = $this->membre('relecteur-b@naja7i.ma', 'editeur');

        $question = $this->questionDe($auteur);
        $this->transitions->submitForReview($question);
        $this->transitions->markReviewed($question->fresh(), $relecteur);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/relecteur/i');

        $this->transitions->validate($question->fresh(), $relecteur);
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
        $auteur = $this->membre('auteur-c@naja7i.ma', 'auteur');
        $relecteur = $this->membre('relecteur-c@naja7i.ma', 'reviseur');

        $question = $this->questionDe($auteur);
        $this->transitions->submitForReview($question);
        $question = $question->fresh();

        $this->assertFalse(
            $relecteur->can('update', $question),
            'Le relecteur ne doit PAS pouvoir amender le contenu : ce n’est pas son métier. '
            .'Si cette assertion tombe, la correction a ouvert une porte de trop.'
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
        $auteur = $this->membre('auteur-d@naja7i.ma', 'auteur');
        $relecteur = $this->membre('relecteur-d@naja7i.ma', 'reviseur');
        $valideur = $this->membre('valideur-d@naja7i.ma', 'editeur');

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
