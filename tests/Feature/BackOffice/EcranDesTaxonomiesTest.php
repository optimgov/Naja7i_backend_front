<?php

namespace Tests\Feature\BackOffice;

use App\Filament\Resources\CompetencyNodes\CompetencyNodeResource;
use App\Filament\Resources\CompetencyNodes\Pages\CreateCompetencyNode;
use App\Filament\Resources\CompetencyNodes\Pages\EditCompetencyNode;
use App\Filament\Resources\CompetencyNodes\Pages\ListCompetencyNodes;
use App\Filament\Resources\TaxonomyProfiles\Pages\CreateTaxonomyProfile;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\MasteryScore;
use App\Models\Question;
use App\Models\Role;
use App\Models\Source;
use App\Models\TaxonomyProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TaxonomieService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Le lot TAXO — l'écran des taxonomies, et la fin des arbres écrits en dur.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CE LOT CHANGE, ET CE QU'IL NE DÉCIDE PAS
 *
 * Le modèle était déjà bon. Ce qui manquait n'était pas la structure, c'était
 * **la main qui la tient** : un arbre se créait par migration, donc par un
 * développeur, pour chaque concours et à chaque fois.
 *
 * Ce fichier ne valide **aucun arbre**. Il prouve qu'on peut en tenir un sans
 * développeur — et que les trois règles qui empêchent un tableur de passer
 * pour un référentiel tiennent : un poids porte sa raison, `official` ne se
 * déclare pas, et un arbre incomplet s'enregistre en le disant.
 */
class EcranDesTaxonomiesTest extends TestCase
{
    use RefreshDatabase;

    private User $pedagogue;

    private Exam $epreuve;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->pedagogue = User::create([
            'email' => 'pedagogue-taxo@naja7i.ma', 'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr', 'status' => 'active',
        ]);
        $this->pedagogue->markEmailAsVerified();
        $this->pedagogue->memberships()->create([
            'role_id' => Role::where('code', 'expert_pedagogue')->whereNull('tenant_id')->value('id'),
        ]);
        $this->pedagogue = $this->pedagogue->fresh();

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
    }

    private function service(): TaxonomieService
    {
        return app(TaxonomieService::class);
    }

    private function noeud(string $code): CompetencyNode
    {
        return CompetencyNode::where('code', $code)->sole();
    }

    /** La justification minimale acceptée par la base — vingt caractères. */
    private const RAISON = 'Recompté sur le corpus du 15 août : 45 questions sur 213.';

    // ═══ Pas 1 — les profils ═══════════════════════════════════════════════

    public function test_un_profil_se_cree_avec_ses_niveaux_nommes_fr_et_ar(): void
    {
        $autre = Exam::where('code', '!=', 'CRMEF-SE-2025')->firstOrFail();
        TaxonomyProfile::where('exam_id', $autre->id)->delete();

        Livewire::actingAs($this->pedagogue)
            ->test(CreateTaxonomyProfile::class)
            ->fillForm([
                'exam_id' => $autre->id,
                'levels' => [
                    ['name_fr' => 'Axe', 'name_ar' => 'محور'],
                    ['name_fr' => 'Thème', 'name_ar' => 'موضوع'],
                    ['name_fr' => 'Chapitre', 'name_ar' => 'فصل'],
                ],
                'min_depth_for_publication' => 1,
                'source_note_fr' => 'Découpe proposée par l’équipe pédagogique, août 2026.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $profil = TaxonomyProfile::where('exam_id', $autre->id)->sole();

        $this->assertSame(3, $profil->depth());
        $this->assertSame('Thème', $profil->levelName(1, 'fr'));
        $this->assertSame('موضوع', $profil->levelName(1, 'ar'));
    }

    public function test_un_profil_sans_nom_de_niveau_est_refuse(): void
    {
        $autre = Exam::where('code', '!=', 'CRMEF-SE-2025')->firstOrFail();
        TaxonomyProfile::where('exam_id', $autre->id)->delete();

        /* Un arbre dont les niveaux n'ont pas de nom produit des écrans
         * candidats qui disent « niveau 2 ». Et sans l'arabe, il les produit
         * pour la moitié arabophone seulement — ce qui est pire, parce que
         * personne ne le voit. */
        Livewire::actingAs($this->pedagogue)
            ->test(CreateTaxonomyProfile::class)
            ->fillForm([
                'exam_id' => $autre->id,
                'levels' => [['name_fr' => 'Axe', 'name_ar' => '']],
                'min_depth_for_publication' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors();

        $this->assertSame(0, TaxonomyProfile::where('exam_id', $autre->id)->count());
    }

    public function test_deux_epreuves_d_une_meme_famille_nomment_leurs_niveaux_differemment(): void
    {
        /* Non-régression ADR-0012 / ADR-0014 : la didactique dit « Bloc », les
         * sciences de l'éducation disent « Domaine ». Les rattacher à la
         * famille aurait forcé à fusionner trois matrices en une. */
        $se = TaxonomyProfile::where('exam_id', $this->epreuve->id)->sole();
        $did = TaxonomyProfile::whereHas('exam', fn ($q) => $q->where('code', 'like', '%DID%'))->first();

        $this->assertNotNull($did, 'Sans seconde épreuve, ce test mesurerait le vide.');
        $this->assertNotSame($se->levelName(0, 'fr'), $did->levelName(0, 'fr'));
        $this->assertSame($se->exam->track->exam_family_id, $did->exam->track->exam_family_id);
    }

    // ═══ Pas 2 — créer, renommer, déplacer ═════════════════════════════════

    public function test_un_noeud_se_cree_depuis_l_ecran_sans_migration(): void
    {
        $racine = $this->noeud('SE-PSY');

        Livewire::actingAs($this->pedagogue)
            ->test(CreateCompetencyNode::class)
            ->fillForm([
                'exam_id' => $this->epreuve->id,
                'parent_id' => $racine->id,
                'code' => 'SE-PSY-MOTIV',
                'name_fr' => 'Motivation et engagement',
                'name_ar' => 'الدافعية والانخراط',
                'position' => 3,
                'provenance' => 'observed',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cree = $this->noeud('SE-PSY-MOTIV');

        $this->assertSame(1, $cree->depth);
        $this->assertSame($racine->path.'.'.$cree->id, $cree->path);
    }

    public function test_un_noeud_se_renomme_et_se_repositionne(): void
    {
        $noeud = $this->noeud('SE-PSY-DEV');

        Livewire::actingAs($this->pedagogue)
            ->test(EditCompetencyNode::class, ['record' => $noeud->getRouteKey()])
            ->fillForm(['name_fr' => 'Psychologie du développement de l’enfant', 'position' => 5])
            ->call('save')
            ->assertHasNoFormErrors();

        $noeud->refresh();

        $this->assertSame('Psychologie du développement de l’enfant', $noeud->name_fr);
        $this->assertSame(5, $noeud->position);
    }

    public function test_le_deplacement_annonce_ses_trois_nombres_avant_de_toucher_quoi_que_ce_soit(): void
    {
        $source = $this->noeud('SE-PSY');
        $cible = $this->noeud('SE-SOC');

        MasteryScore::create([
            'user_id' => $this->pedagogue->id, 'exam_id' => $this->epreuve->id,
            'competency_node_id' => $this->noeud('SE-PSY-DEV')->id,
            'score' => 50.0, 'evidence' => 'sufficient', 'answered_count' => 10,
            'correct_count' => 5, 'skipped_count' => 0, 'lucky_guess_count' => 0,
            'confident_error_count' => 0, 'computed_at' => now(),
        ]);

        $avant = $this->service()->impactDuDeplacement($source, $cible);

        $this->assertSame(2, $avant['descendants'], 'SE-PSY porte deux sous-domaines.');
        $this->assertSame(1, $avant['scores']);
        $this->assertSame(1, $avant['profondeur_apres']);

        /* ANNONCER N'ÉCRIT RIEN — c'est toute la garde de DET-88. */
        $this->assertSame(0, $source->fresh()->depth);
        $this->assertNull($source->fresh()->parent_id);
    }

    public function test_le_deplacement_reecrit_tout_le_sous_arbre_dans_une_transaction(): void
    {
        $source = $this->noeud('SE-PSY');
        $cible = $this->noeud('SE-SOC');
        $enfant = $this->noeud('SE-PSY-DEV');

        $question = Question::where('competency_node_id', $enfant->id)->first();

        $this->service()->deplacer($source, $cible);

        $source->refresh();
        $enfant->refresh();
        $cible->refresh();

        $this->assertSame($cible->id, $source->parent_id);
        $this->assertSame(1, $source->depth);
        $this->assertSame($cible->path.'.'.$source->id, $source->path);

        /* LE SOUS-ARBRE ENTIER, pas seulement le nœud déplacé. C'est le point :
         * un chemin incohérent ne lève aucune erreur, il rend des sous-arbres
         * faux, et personne ne le voit. */
        $this->assertSame(2, $enfant->depth);
        $this->assertSame($source->path.'.'.$enfant->id, $enfant->path);

        /* Le sous-arbre du nœud d'accueil contient bien tout le monde : ses deux
         * enfants d'origine, plus SE-PSY et ses deux sous-domaines. */
        $this->assertSame(
            5,
            CompetencyNode::descendantsOf($cible)->count(),
            'SE-PSY et ses deux enfants pendent désormais sous SE-SOC, à côté des siens.',
        );

        /* Les questions et les scores SUIVENT sans être réécrits : ils pointent
         * vers le nœud, jamais vers le chemin. */
        if ($question !== null) {
            $this->assertSame($enfant->id, $question->fresh()->competency_node_id);
        }
    }

    public function test_un_cycle_est_refuse(): void
    {
        $parent = $this->noeud('SE-PSY');
        $enfant = $this->noeud('SE-PSY-DEV');

        $this->expectException(RuntimeException::class);

        /* Le modèle le tient déjà (`assertNoCycle`) ; le service ne le réécrit
         * pas, il l'appelle. Deux règles pour un même invariant dériveraient. */
        $this->service()->deplacer($parent, $enfant);
    }

    public function test_un_noeud_ne_se_deplace_pas_sous_une_autre_epreuve(): void
    {
        $ailleurs = CompetencyNode::where('exam_id', '!=', $this->epreuve->id)
            ->whereNotNull('exam_id')->first();

        $this->assertNotNull($ailleurs, 'Sans nœud d’une autre épreuve, ce test mesurerait le vide.');

        try {
            $this->service()->deplacer($this->noeud('SE-PSY'), $ailleurs);
            $this->fail('Un nœud ne sert jamais deux matrices (ADR-0014).');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('autre épreuve', $e->validator->errors()->first('parent_id'));
        }
    }

    // ═══ Pas 3 — les poids, et la vérité sur leur origine ══════════════════

    public function test_un_poids_sans_justification_ecrite_est_refuse(): void
    {
        $this->expectException(QueryException::class);

        /* La contrainte est EN BASE : un semis, une commande ou un correctif à
         * chaud passent par le même refus que l'écran. */
        CompetencyNode::create([
            'exam_id' => $this->epreuve->id,
            'code' => 'SE-SANS-RAISON',
            'name_fr' => 'Sans raison', 'name_ar' => 'بلا سبب',
            'weight_percent' => 25, 'position' => 9, 'provenance' => 'observed',
        ]);
    }

    public function test_l_ecran_refuse_lui_aussi_un_poids_sans_justification(): void
    {
        Livewire::actingAs($this->pedagogue)
            ->test(CreateCompetencyNode::class)
            ->fillForm([
                'exam_id' => $this->epreuve->id,
                'code' => 'SE-ECRAN-SANS-RAISON',
                'name_fr' => 'Sans raison', 'name_ar' => 'بلا سبب',
                'weight_percent' => 25, 'position' => 9, 'provenance' => 'observed',
            ])
            ->call('create')
            ->assertHasFormErrors(['weight_justification']);
    }

    public function test_un_poids_ne_passe_pas_en_officiel_sans_source_verifiee(): void
    {
        $noeud = $this->noeud('SE-PSY');
        $nonVerifiee = Source::whereNull('verified_at')->first();

        $this->assertNotNull($nonVerifiee, 'Sans source non vérifiée, ce test mesurerait le vide.');

        /* POINT DE REPRISE OBLIGATOIRE. Une erreur PostgreSQL avorte la
         * transaction entière : sans `DB::transaction` imbriqué — donc sans
         * SAVEPOINT — la moindre requête suivante échouerait « current
         * transaction is aborted », et le test mesurerait sa propre casse. */
        try {
            DB::transaction(fn () => $noeud
                ->forceFill(['provenance' => 'official', 'source_id' => $nonVerifiee->id])
                ->save());

            $this->fail('« Officiel » ne se déclare pas : il s’établit.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('VERIFIEE', $e->getMessage());
        }

        $this->assertSame('reported', $noeud->fresh()->provenance);
    }

    public function test_l_ecran_ne_propose_meme_pas_officiel(): void
    {
        /* La liste déroulante ne porte que ce qui est tenable. Le déclencheur
         * reste la serrure ; ceci évite d'offrir un bouton qui échoue. */
        $this->assertSame(
            ['reported', 'observed'],
            array_keys(CompetencyNodeResource::provenancesChoisissables()),
        );
    }

    public function test_un_poids_devient_officiel_quand_la_source_est_verifiee(): void
    {
        $noeud = $this->noeud('SE-PSY');
        $source = Source::whereNull('verified_at')->firstOrFail();

        $source->forceFill(['verified_at' => now(), 'verified_by' => $this->pedagogue->id])->save();

        $noeud->forceFill(['provenance' => 'official', 'source_id' => $source->id])->save();

        $this->assertSame('official', $noeud->fresh()->provenance);
    }

    public function test_une_fratrie_dont_la_somme_n_est_pas_cent_s_enregistre_et_le_dit(): void
    {
        $noeud = $this->noeud('SE-PSY');

        $this->assertTrue($this->service()->sommeDeLaFratrie($noeud)['complete']);

        /* On enlève dix points : l'arbre devient partiel. Il doit rester
         * enregistrable — un arbre en travaux n'est pas un arbre faux. */
        Livewire::actingAs($this->pedagogue)
            ->test(EditCompetencyNode::class, ['record' => $noeud->getRouteKey()])
            ->fillForm(['weight_percent' => 30, 'weight_justification' => self::RAISON])
            ->call('save')
            ->assertHasNoFormErrors();

        $somme = $this->service()->sommeDeLaFratrie($noeud->fresh());

        $this->assertSame(90.0, $somme['total']);
        $this->assertSame(-10.0, $somme['ecart']);
        $this->assertFalse($somme['complete']);

        /* ET L'ÉCART EST DIT. Un total de 90 % qui ne se voit nulle part
         * devient un arbre faux que personne ne rattrape. */
        Livewire::actingAs($this->pedagogue)
            ->test(EditCompetencyNode::class, ['record' => $noeud->getRouteKey()])
            ->assertSee('ne fait pas 100');
    }

    // ═══ Pas 4 — l'écran suffit-il à faire exister un arbre ? ══════════════

    public function test_un_sous_arbre_entier_se_construit_par_le_seul_ecran(): void
    {
        /*
         * LE TEST GRANDEUR NATURE DU LOT : si l'écran ne suffit pas à faire
         * exister des nœuds, il n'est pas fini.
         *
         * Quatre nœuds — un domaine et ses trois sous-domaines — créés
         * uniquement par le formulaire, avec leurs poids justifiés et leur
         * provenance `observed`, puis vérifiés dans l'arbre. Aucune migration,
         * aucun semis.
         *
         * ═══════════════════════════════════════════════════════════════════
         * CES QUATRE NŒUDS NE SONT PAS UN ARBRE DÉCIDÉ
         *
         * La mission parlait de « quatre nœuds SE commentés dans les semis ».
         * Ils n'y sont pas — vérifié : `Crmef2025Seeder` ne porte aucune ligne
         * de nœud en commentaire, et aucun document du dépôt ne les nomme.
         * L'écart est consigné au retour.
         *
         * Faute de les connaître, ce test en fabrique quatre qui lui
         * ressemblent, POUR ÉPROUVER L'ÉCRAN et rien d'autre. Ils vivent dans
         * la transaction du test et n'atteignent aucune base : le lot ne décide
         * aucun arbre, et celui-ci moins que tout autre.
         */
        $quatre = [
            ['SE-EVAL', null, 'Évaluation des apprentissages', 'تقويم التعلمات', 25],
            ['SE-EVAL-FONCTIONS', 'SE-EVAL', 'Fonctions de l’évaluation', 'وظائف التقويم', 10],
            ['SE-EVAL-OUTILS', 'SE-EVAL', 'Outils et instruments', 'أدوات التقويم', 8],
            ['SE-EVAL-REMEDIATION', 'SE-EVAL', 'Remédiation et soutien', 'الدعم والمعالجة', 7],
        ];

        foreach ($quatre as $i => [$code, $parent, $fr, $ar, $poids]) {
            Livewire::actingAs($this->pedagogue)
                ->test(CreateCompetencyNode::class)
                ->fillForm([
                    'exam_id' => $this->epreuve->id,
                    'parent_id' => $parent === null ? null : $this->noeud($parent)->id,
                    'code' => $code,
                    'name_fr' => $fr,
                    'name_ar' => $ar,
                    'position' => $i + 1,
                    'weight_percent' => $poids,
                    'weight_justification' => self::RAISON,
                    'provenance' => 'observed',
                ])
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $domaine = $this->noeud('SE-EVAL');

        $this->assertSame(0, $domaine->depth);
        $this->assertSame(3, CompetencyNode::descendantsOf($domaine)->count());
        $this->assertSame('observed', $domaine->provenance);

        foreach (['SE-EVAL-FONCTIONS', 'SE-EVAL-OUTILS', 'SE-EVAL-REMEDIATION'] as $code) {
            $enfant = $this->noeud($code);

            $this->assertSame(1, $enfant->depth);
            $this->assertSame($domaine->path.'.'.$enfant->id, $enfant->path);
            /* PUBLIABLES : le profil SE publie dès la profondeur 1. */
            $this->assertTrue(
                $this->epreuve->taxonomyProfile->allowsPublicationAt($enfant->depth),
                'Un nœud créé par l’écran doit pouvoir porter une question publiée.',
            );
        }

        /* L'arbre a grossi de 25 points : l'écart est dit, pas refusé. */
        $this->assertSame(125.0, $this->service()->sommeDeLaFratrie($domaine)['total']);
    }

    public function test_la_liste_rend_l_arbre_dans_l_ordre_du_chemin(): void
    {
        Livewire::actingAs($this->pedagogue)
            ->test(ListCompetencyNodes::class)
            ->set('tableRecordsPerPage', 50)
            ->assertCanSeeTableRecords(
                CompetencyNode::where('exam_id', $this->epreuve->id)->orderBy('path')->get(),
                inOrder: true,
            );
    }
}
