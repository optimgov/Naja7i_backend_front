<?php

namespace Tests\Feature\BackOffice;

use App\Filament\Resources\Questions\Pages\ListQuestions;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * D-04 — UN FILTRE N'EST PAS UN ANNUAIRE.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE LA RECETTE HUMAINE A VU
 *
 * `/admin/questions`, panneau des filtres, filtre « Auteur ». Il listait TOUS
 * les comptes du tenant, adresses en clair : les quatre candidats de la base,
 * dont celui que je venais de créer par le formulaire public d'inscription.
 * Aucun candidat n'a jamais rédigé de question.
 *
 * Le compte qui voyait cela — `editorial.relecteur` — porte `questions.view`,
 * `questions.review` et `catalogue.view`. Ni `members.view`, ni `users.support`.
 * Ce sont pourtant ces deux permissions-là qui gouvernent l'accès aux personnes.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA CAUSE, ET POURQUOI ELLE EST DE LA MÊME FAMILLE QUE D-16 ET D-02
 *
 *     SelectFilter::make('author_id')->relationship('author', 'email')
 *
 * `relationship()` construit ses options depuis le modèle lié, SANS CONTRAINTE.
 * Il ne demande pas « qui a écrit une question », il demande « quels
 * utilisateurs existent ». La donnée servie n'est donc pas gouvernée par la
 * permission de son métier — c'est exactement la règle 2 de la phase PORTES,
 * transposée d'une action vers une LISTE D'OPTIONS.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QU'ON CORRIGE, ET CE QU'ON NE CORRIGE PAS
 *
 * On ne restreint pas le filtre au personnel : ce serait viser à côté. Un
 * membre du personnel qui n'a jamais rien écrit n'a pas plus à figurer dans un
 * filtre « Auteur » qu'un candidat. Le bon ensemble est le seul qui ait un
 * sens ici — LES COMPTES QUI ONT EFFECTIVEMENT ÉCRIT AU MOINS UNE QUESTION.
 * Il est plus petit que « le personnel », il ne demande aucune liste à tenir,
 * et il se rétrécit tout seul quand une question est retirée.
 */
class FiltresSansAnnuaireTest extends TestCase
{
    use RefreshDatabase;

    private User $relecteur;

    private User $auteur;

    private User $candidat;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->auteur = $this->membre('auteur-filtre@naja7i.ma', 'expert_pedagogue');
        $this->relecteur = $this->membre('relecteur-filtre@naja7i.ma', 'expert_pedagogue');

        /* UN CANDIDAT, avec une adresse reconnaissable. C'est LUI qu'on ne doit
         * pas trouver dans le filtre — et son adresse est le fait qui fuyait. */
        $this->candidat = $this->membre('candidat.prive@naja7i.test', 'candidat');

        $this->questionDe($this->auteur);
    }

    /**
     * LE FILTRE NE MONTRE QUE CEUX QUI ONT ÉCRIT.
     *
     * On lit les options telles que la PAGE les rend, pas telles qu'on les
     * imagine : c'est le rendu qui fuyait.
     */
    public function test_le_filtre_auteur_n_expose_pas_les_candidats(): void
    {
        $composant = Livewire::actingAs($this->relecteur)->test(ListQuestions::class);

        $options = $this->optionsDuFiltre($composant);

        $this->assertNotContains(
            $this->candidat->email,
            $options,
            'L’adresse d’un CANDIDAT apparaît dans le filtre « Auteur » du back-office. '
            .'Le compte qui la lit ne porte ni `members.view` ni `users.support` — les '
            .'deux permissions qui gouvernent l’accès aux personnes. Un filtre n’est pas '
            .'un annuaire. Options servies : '.implode(', ', $options)
        );

        $this->assertContains(
            $this->auteur->email,
            $options,
            'Le filtre doit rester utile : celui qui a écrit la question doit y figurer.'
        );
    }

    /**
     * LE PENDANT, ET IL N'EST PAS DÉCORATIF.
     *
     * Un filtre vide satisferait le test précédent. Celui-ci exige qu'il serve
     * encore à quelque chose, et qu'il ne contienne QUE des auteurs réels — y
     * compris pas les membres du personnel qui n'ont rien écrit.
     */
    public function test_le_filtre_auteur_ne_contient_que_des_auteurs_reels(): void
    {
        $composant = Livewire::actingAs($this->relecteur)->test(ListQuestions::class);

        $options = $this->optionsDuFiltre($composant);

        $this->assertNotEmpty($options, 'Un filtre vide ne protège rien, il casse la page.');

        $auteursReels = User::whereIn('id', Question::query()->select('author_id'))
            ->pluck('email')
            ->all();

        $this->assertEqualsCanonicalizing(
            $auteursReels,
            $options,
            'Le filtre « Auteur » doit contenir exactement les comptes qui ont écrit au '
            .'moins une question — ni plus (fuite), ni moins (filtre inutile). Le '
            .'relecteur, qui n’a rien écrit, n’a pas à y figurer non plus.'
        );
    }

    /** @return list<string> */
    private function optionsDuFiltre($composant): array
    {
        $filtre = $composant->instance()->getTable()->getFilter('author_id');

        return array_values($filtre->getOptions());
    }

    private function membre(string $email, string $role): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
            'status' => 'active',
        ]);
        $user->markEmailAsVerified();
        $user->memberships()->create([
            'role_id' => Role::where('code', $role)->whereNull('tenant_id')->value('id'),
        ]);

        return $user->fresh();
    }

    private function questionDe(User $auteur): Question
    {
        $epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();

        return Question::create([
            'exam_id' => $epreuve->id,
            'competency_node_id' => CompetencyNode::where('exam_id', $epreuve->id)->where('depth', 1)->firstOrFail()->id,
            'locale' => 'fr',
            'sibling_group' => (string) Str::uuid7(),
            'stem' => 'Énoncé du filtre',
            'explanation' => 'Justification.',
            'author_id' => $auteur->id,
        ]);
    }
}
