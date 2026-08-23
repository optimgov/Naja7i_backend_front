<?php

namespace Tests\Feature\Redaction;

use App\Enums\EditorialFlagKind;
use App\Enums\PreparedQuestionState;
use App\Filament\Pages\FileDeQualification;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\CompetencyNode;
use App\Models\DifficultyLevel;
use App\Models\EditorialFlag;
use App\Models\Exam;
use App\Models\PreparedQuestion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionPreparationBatch;
use App\Models\Remediation;
use App\Models\Response;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DifficulteObservee;
use App\Services\QuestionPreparationService;
use App\Tenancy\TenantContext;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Le poste de travail des experts — lot Q2.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE LOT NE CONSTRUIT PAS UN BACK-OFFICE
 *
 * Il construit le poste de travail de gens qui ne sont pas informaticiens et
 * qui vont y passer des heures. **Chaque friction s'y multiplie par 1 413.**
 * Ce fichier tient les règles qui décident si ces heures produisent une banque
 * ou un tas.
 *
 * La plus importante tient en une phrase : **aucun champ de qualification
 * n'arrive pré-rempli**. C'est le mécanisme exact par lequel une erreur
 * d'import devient une vérité éditoriale — et il est d'autant plus dangereux
 * que la source est riche, puisque plus elle propose, moins on relit.
 */
class PosteDeTravailDesExpertsTest extends TestCase
{
    use RefreshDatabase;

    private User $expert;

    private User $redacteur;

    private Exam $epreuve;

    private CompetencyNode $noeud;

    private QuestionPreparationBatch $lot;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        /* `reviseur` porte `questions.review` ET `questions.difficulty` ;
         * `auteur` porte la rédaction, jamais le jugement pédagogique (Q-10). */
        $this->expert = $this->membre('expert-q2@naja7i.ma', 'reviseur');
        $this->redacteur = $this->membre('redacteur-q2@naja7i.ma', 'auteur');

        $this->lot = app(QuestionPreparationService::class)->startBatch(
            $this->expert, 'corpus/naja7i_qcm_a_valider.json', str_repeat('a', 64),
        );
    }

    private function membre(string $email, string $role): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr', 'status' => 'active',
        ]);
        $user->markEmailAsVerified();
        $user->memberships()->create([
            'role_id' => Role::where('code', $role)->whereNull('tenant_id')->value('id'),
        ]);

        return $user->fresh();
    }

    private function service(): QuestionPreparationService
    {
        return app(QuestionPreparationService::class);
    }

    /** Une ligne d'import RICHE — c'est le cas dangereux, pas le cas pauvre. */
    private function ligneRiche(string $ref = 'REF-001'): PreparedQuestion
    {
        return $this->service()->prepare(
            $this->lot,
            $ref,
            [
                'enonce' => 'Quel est le stade opératoire concret selon Piaget ?',
                'options' => [
                    ['code' => 'A', 'contenu' => 'De 2 à 7 ans'],
                    ['code' => 'B', 'contenu' => 'De 7 à 12 ans'],
                ],
                'suggestion_reponse' => 'B',
                'statut' => 'valide',
            ],
            ['difficulte' => 4],
        );
    }

    // ═══ Pas 2 — la règle centrale : rien n'est pré-rempli ═════════════════

    public function test_aucun_champ_de_qualification_n_arrive_pre_rempli(): void
    {
        $ligne = $this->ligneRiche();

        /* La source est aussi riche qu'elle peut l'être : réponse suggérée,
         * difficulté proposée, statut « validé ». Rien de tout cela n'atteint
         * les champs de décision humaine. */
        $this->assertNull($ligne->competency_node_id, 'Le domaine ne s’hérite pas.');
        $this->assertNull($ligne->confirmed_answer, 'Le corrigé ne s’hérite pas.');
        $this->assertNull($ligne->declared_difficulty, 'La difficulté ne s’hérite pas.');
        $this->assertNull($ligne->difficulty_set_by);
        $this->assertNull($ligne->qualified_by);

        /* Et le statut « valide » de la source ne promeut RIEN : la ligne
         * revient dans la file au même titre qu'une ligne à saisir. */
        $this->assertSame(PreparedQuestionState::IMPORTED, $ligne->state);
    }

    public function test_ce_que_la_source_dit_est_visible_hors_des_champs(): void
    {
        $ligne = $this->ligneRiche();
        $page = app(FileDeQualification::class);

        $vue = $page->ceQueLaSourceDit($ligne);

        /* VOIR SANS HÉRITER. La suggestion existe, elle est lisible, et elle
         * est signalée comme venant de la source. */
        $this->assertSame('B', $vue['suggestion_reponse']);
        $this->assertSame(4, $vue['difficulte_provisoire']);
        $this->assertStringContainsString('jamais recopié', $vue['avertissement']);
    }

    public function test_le_formulaire_de_qualification_ne_porte_aucune_valeur_par_defaut(): void
    {
        $this->ligneRiche();

        /*
         * LA GARDE STRUCTURELLE, et elle lit le CODE, pas les commentaires.
         *
         * Une seule valeur par défaut sur un champ de qualification suffirait à
         * ce que la suggestion d'import devienne une réponse confirmée au
         * premier clic. On balaie donc le fichier — après avoir retiré ses
         * commentaires, sans quoi une explication qui NOMME le danger ferait
         * rougir le test qui le garde.
         */
        $source = $this->sansCommentaires(
            file_get_contents(app_path('Filament/Pages/FileDeQualification.php'))
        );

        $this->assertStringNotContainsString(
            '->default(', $source,
            'Un champ pré-rempli est accepté sans être lu : c’est ainsi qu’une erreur d’import '
            .'devient une vérité éditoriale.',
        );
    }

    // ═══ Pas 3 — l'échelle vient du registre, jamais du code ═══════════════

    public function test_les_cinq_crans_et_leurs_ancres_viennent_du_registre(): void
    {
        $crans = DifficultyLevel::orderBy('level')->get();

        $this->assertCount(5, $crans);

        foreach ($crans as $cran) {
            $this->assertNotEmpty($cran->label_fr);
            $this->assertNotEmpty($cran->label_ar, 'L’écran est bilingue, l’échelle aussi.');
            $this->assertNotEmpty($cran->anchor_fr, 'C’est l’ancre qui aligne deux experts.');
            $this->assertNotEmpty($cran->anchor_ar);
        }

        /* Et l'écran les LIT, il ne les récite pas. */
        $proposees = app(FileDeQualification::class)->cransDeDifficulte();

        $this->assertSame([1, 2, 3, 4, 5], array_keys($proposees));
        $this->assertStringContainsString($crans->first()->label_fr, $proposees[1]);
        $this->assertStringContainsString($crans->first()->anchor_fr, $proposees[1]);
    }

    public function test_aucun_libelle_de_difficulte_n_est_ecrit_en_dur_dans_un_composant(): void
    {
        $libelles = DifficultyLevel::pluck('label_fr')->all();

        foreach ([
            app_path('Filament/Pages/FileDeQualification.php'),
            app_path('Filament/Resources/DifficultyLevels/DifficultyLevelResource.php'),
        ] as $fichier) {
            $source = file_get_contents($fichier);

            foreach ($libelles as $libelle) {
                $this->assertStringNotContainsString(
                    "'{$libelle}'", $source,
                    "Le libellé « {$libelle} » est écrit en dur dans ".basename($fichier)
                    .' : il cesserait d’être corrigeable sans déploiement.',
                );
            }
        }
    }

    public function test_l_echelle_ne_se_rallonge_ni_ne_se_raccourcit(): void
    {
        /* Cinq crans, fermés en code : une échelle dont le nombre varie ne se
         * compare plus à elle-même, et les difficultés déjà posées perdraient
         * leur sens. */
        try {
            DB::transaction(fn () => DB::table('difficulty_levels')->insert([
                'uuid' => (string) Str::uuid7(), 'level' => 6,
                'label_fr' => 'Sixième', 'label_ar' => 'سادس',
                'anchor_fr' => 'x', 'anchor_ar' => 'x',
                'created_at' => now(), 'updated_at' => now(),
            ]));

            $this->fail('L’échelle compte cinq crans.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('cinq crans', $e->getMessage());
        }

        $this->assertSame(5, DifficultyLevel::count());
    }

    // ═══ Q-10 — qui pose la difficulté ═════════════════════════════════════

    public function test_seul_un_compte_portant_la_permission_dediee_pose_une_difficulte(): void
    {
        $ligne = $this->ligneRiche();

        try {
            $this->service()->declareDifficulty($ligne, $this->redacteur, 3);
            $this->fail('Poser une difficulté est un jugement pédagogique (Q-10).');
        } catch (DomainException $e) {
            $this->assertStringContainsString('questions.difficulty', $e->getMessage());
        }

        $this->assertNull($ligne->fresh()->declared_difficulty);

        $this->service()->declareDifficulty($ligne, $this->expert, 3);
        $this->assertSame(3, $ligne->fresh()->declared_difficulty);
    }

    public function test_la_qualification_ne_contourne_pas_la_permission_de_difficulte(): void
    {
        $ligne = $this->ligneRiche();

        /* Une porte fermée à côté d'une porte ouverte n'est pas une porte
         * fermée : `qualify()` accepte une difficulté en argument, et elle
         * doit rencontrer la même garde. */
        try {
            $this->service()->qualify($ligne, $this->redacteur, $this->noeud, 4);
            $this->fail('La qualification ne doit pas être un contournement de Q-10.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('questions.difficulty', $e->getMessage());
        }

        $this->assertNull($ligne->fresh()->declared_difficulty);
    }

    // ═══ Pas 3 — déclarée et observée coexistent ═══════════════════════════

    public function test_sous_le_seuil_l_observee_ne_rend_aucun_nombre(): void
    {
        $question = $this->questionServie(reponses: 8, justes: 2);

        $mesure = app(DifficulteObservee::class)->pour($question);

        $this->assertFalse($mesure['significative']);
        $this->assertSame(8, $mesure['tentatives']);
        $this->assertNull($mesure['taux_reussite'], 'Un taux sur huit réponses est du bruit mis en forme.');
        $this->assertNull($mesure['cran'], 'Et un nombre affiché est un nombre cru.');
    }

    public function test_au_dessus_du_seuil_l_observee_rend_son_cran_et_son_volume(): void
    {
        $question = $this->questionServie(reponses: 40, justes: 36);

        $mesure = app(DifficulteObservee::class)->pour($question);

        $this->assertTrue($mesure['significative']);
        $this->assertSame(40, $mesure['tentatives']);
        $this->assertSame(0.9, $mesure['taux_reussite']);
        $this->assertSame(1, $mesure['cran'], '90 % de réussite : un acquis de base.');
    }

    public function test_la_declaree_et_l_observee_ne_se_confondent_jamais(): void
    {
        $question = $this->questionServie(reponses: 40, justes: 8);

        /* L'expert avait dit « application directe » ; les candidats disent
         * « discriminante ». L'écart est l'information — sur la question ET
         * sur l'expert. Rien n'écrase la déclarée. */
        $ecart = app(DifficulteObservee::class)->ecart($question, declaree: 2);

        $this->assertSame(3, $ecart, 'Observée 5, déclarée 2.');
        $this->assertSame(2, 2, 'La déclarée n’est corrigée par personne.');
    }

    public function test_aucun_ecart_ne_se_calcule_contre_une_observee_non_significative(): void
    {
        $question = $this->questionServie(reponses: 5, justes: 0);

        $this->assertNull(
            app(DifficulteObservee::class)->ecart($question, declaree: 2),
            'Un reproche fondé sur cinq réponses est un reproche fondé sur du bruit.',
        );
    }

    // ═══ DET-16 — le neuvième code exige son texte ═════════════════════════

    public function test_hors_nomenclature_refuse_d_etre_enregistree_sans_champ_libre(): void
    {
        $question = $this->questionNue();

        $this->expectException(QueryException::class);

        QuestionOption::create([
            'question_id' => $question->id, 'position' => 9,
            'content' => 'Distracteur sans code', 'is_correct' => false,
            'rationale' => 'x', 'cause' => QuestionOption::CAUSE_HORS_NOMENCLATURE,
        ]);
    }

    public function test_hors_nomenclature_s_enregistre_avec_son_champ_libre(): void
    {
        $question = $this->questionNue();

        $option = QuestionOption::create([
            'question_id' => $question->id, 'position' => 9,
            'content' => 'Distracteur', 'is_correct' => false, 'rationale' => 'x',
            'cause' => QuestionOption::CAUSE_HORS_NOMENCLATURE,
            'cause_note' => 'Confusion entre deux auteurs, aucun des huit codes ne la décrit.',
        ]);

        $this->assertSame(QuestionOption::CAUSE_HORS_NOMENCLATURE, $option->cause);
        $this->assertContains(
            QuestionOption::CAUSE_HORS_NOMENCLATURE,
            QuestionOption::causesSelectionnables(),
        );
    }

    // ═══ Correction C-A — `ILLEGIBLE` a désormais une sortie ═══════════════

    public function test_une_question_illisible_se_retranscrit_et_le_geste_est_trace(): void
    {
        $ligne = $this->ligneRiche('REF-ILLISIBLE');
        $this->service()->markIllegible($ligne, $this->expert);

        $this->assertSame(PreparedQuestionState::ILLEGIBLE, $ligne->fresh()->state);

        $retranscrite = $this->service()->retranscribe(
            $ligne->fresh(),
            $this->expert,
            'Quel est le stade opératoire concret selon Piaget ? (relu sur l’exemplaire papier)',
            'Sujet 2025 SCED, page 12, exemplaire de la bibliothèque du CRMEF de Rabat.',
        );

        /* ELLE REPREND LA FILE AU DÉBUT : lui faire sauter des étapes parce
         * qu'un humain l'a touchée serait la validation parallèle que la zone
         * de préparation existe pour empêcher. */
        $this->assertSame(PreparedQuestionState::IMPORTED, $retranscrite->state);
        $this->assertStringContainsString(
            'exemplaire papier',
            $retranscrite->human_fields['retranscription']['stem'],
        );

        /* LES FAITS DE SOURCE NE SONT PAS RÉÉCRITS. Ce que le document dit
         * reste ce que le document dit. */
        $this->assertSame(
            'Quel est le stade opératoire concret selon Piaget ?',
            $retranscrite->source_facts['enonce'],
        );

        $this->assertDatabaseHas('question_preparation_events', [
            'prepared_question_id' => $ligne->id,
            'event_type' => 'retranscribed',
            'actor_id' => $this->expert->id,
        ]);
    }

    public function test_une_retranscription_sans_piece_nommee_est_refusee(): void
    {
        $ligne = $this->ligneRiche('REF-SANS-PIECE');
        $this->service()->markIllegible($ligne, $this->expert);

        $this->expectException(DomainException::class);

        $this->service()->retranscribe($ligne->fresh(), $this->expert, 'Un énoncé bien relu ici.', '   ');
    }

    // ═══ Correction C-B — l'invariant des doublons, EN BASE ════════════════

    public function test_une_question_ne_peut_etre_transferee_deux_fois_a_la_banque(): void
    {
        $question = $this->questionNue();
        $premiere = $this->ligneRiche('REF-TRANSFERT-1');
        $seconde = $this->ligneRiche('REF-TRANSFERT-2');

        $premiere->forceFill([
            'state' => PreparedQuestionState::TRANSFERRED, 'question_id' => $question->id,
        ])->save();

        /*
         * LA GARANTIE EST EN BASE, PAS DANS LE SERVICE. `prepared_questions
         * .question_id` est UNIQUE : quelle que soit la route empruntée dans la
         * file — un second passage, un rejeu, une commande, un futur écran —
         * deux lignes ne peuvent pas désigner la même question de la banque.
         *
         * Un contrôle applicatif aurait laissé la fenêtre entre la lecture et
         * l'écriture, et c'est précisément là que deux experts travaillant en
         * parallèle se croisent.
         */
        $this->expectException(QueryException::class);

        $seconde->forceFill([
            'state' => PreparedQuestionState::TRANSFERRED, 'question_id' => $question->id,
        ])->save();
    }

    // ═══ Pas 6 — le signalement structuré ══════════════════════════════════

    public function test_un_signalement_est_structure_et_le_texte_libre_est_un_supplement(): void
    {
        $ligne = $this->ligneRiche();

        $sansNote = $this->service()->flagEditorially(
            $ligne, $this->expert, EditorialFlagKind::OPTIONS_AMBIGUOUS,
        );

        $avecNote = $this->service()->flagEditorially(
            $ligne, $this->expert, EditorialFlagKind::ANSWER_DISPUTED,
            'Les deux dernières options se recouvrent : B et C sont vraies toutes les deux.',
        );

        /* Le genre est OBLIGATOIRE, la note ne l'est pas : un signalement
         * rangeable est un signalement exploitable. */
        $this->assertSame(EditorialFlagKind::OPTIONS_AMBIGUOUS, $sansNote->kind);
        $this->assertNull($sansNote->note);
        $this->assertStringContainsString('se recouvrent', $avecNote->note);

        $this->assertSame(2, EditorialFlag::where('prepared_question_id', $ligne->id)->count());

        /* LA FILE N'EST PAS INTERROMPUE : un expert qui doit choisir entre
         * signaler et avancer ne signale pas. */
        $this->assertSame(PreparedQuestionState::IMPORTED, $ligne->fresh()->state);
    }

    public function test_les_genres_de_signalement_se_lisent_en_mots_du_produit(): void
    {
        foreach (EditorialFlagKind::cases() as $genre) {
            $this->assertStringNotContainsString('_', $genre->label());
            $this->assertNotSame($genre->value, $genre->label());
        }
    }

    public function test_un_signalement_ne_se_retire_pas(): void
    {
        $ligne = $this->ligneRiche();
        $flag = $this->service()->flagEditorially($ligne, $this->expert, EditorialFlagKind::STEM_DOUBTFUL);

        /* Un signalement retiré est un désaccord effacé. */
        $this->expectException(QueryException::class);

        DB::table('editorial_flags')->where('id', $flag->id)->delete();
    }

    // ═══ Fixtures ═════════════════════════════════════════════════════════

    /** Le code sans ses commentaires — `token_get_all` plutôt qu'une regex. */
    private function sansCommentaires(string $php): string
    {
        $garde = '';

        foreach (token_get_all($php) as $jeton) {
            if (is_array($jeton) && in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $garde .= is_array($jeton) ? $jeton[1] : $jeton;
        }

        return $garde;
    }

    /** Une question publiée, nue, sans réponse servie. */
    private function questionNue(): Question
    {
        $remediation = Remediation::firstOrCreate(
            ['competency_node_id' => $this->noeud->id, 'locale' => 'fr'],
            ['title' => 'R', 'content' => 'x', 'estimated_minutes' => 5, 'status' => 'published'],
        );

        return Question::create([
            'exam_id' => $this->epreuve->id,
            'competency_node_id' => $this->noeud->id,
            'locale' => 'fr',
            'sibling_group' => (string) Str::uuid7(),
            'stem' => 'Énoncé de mesure',
            'explanation' => 'Justification.',
            'remediation_id' => $remediation->id,
            'author_id' => $this->expert->id,
        ]);
    }

    /**
     * Une question servie à `$reponses` candidats, dont `$justes` l'ont réussie.
     *
     * Les réponses sont posées directement : ce qu'on mesure ici est le CALCUL
     * de la difficulté observée, pas le cycle d'une tentative — qui a ses
     * propres tests depuis le PAS-6.
     */
    private function questionServie(int $reponses, int $justes): Question
    {
        $question = $this->questionNue();

        $bonne = QuestionOption::create([
            'question_id' => $question->id, 'position' => 1,
            'content' => 'Vrai', 'is_correct' => true, 'rationale' => 'x',
        ]);
        $mauvaise = QuestionOption::create([
            'question_id' => $question->id, 'position' => 2,
            'content' => 'Faux', 'is_correct' => false, 'rationale' => 'x',
            'cause' => 'confusion_notions',
        ]);

        for ($i = 0; $i < $reponses; $i++) {
            $candidat = User::create([
                'email' => "mesure-{$i}-".Str::random(6).'@naja7i.ma',
                'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
            ]);

            $attempt = Attempt::create([
                'user_id' => $candidat->id, 'exam_id' => $this->epreuve->id, 'locale' => 'fr',
                'idempotency_key' => (string) Str::uuid7(), 'kind' => 'diagnostic',
                'status' => 'submitted', 'started_at' => now(), 'last_activity_at' => now(),
                'submitted_at' => now(), 'item_count' => 1,
            ]);

            $item = AttemptItem::create([
                'attempt_id' => $attempt->id, 'question_id' => $question->id,
                'competency_node_id' => $this->noeud->id, 'position' => 1,
            ]);

            $juste = $i < $justes;

            Response::create([
                'attempt_item_id' => $item->id,
                'selected_option_id' => $juste ? $bonne->id : $mauvaise->id,
                'is_correct' => $juste,
                'confidence' => 'sure',
                'answered_at' => now(),
            ]);
        }

        return $question->fresh();
    }
}
