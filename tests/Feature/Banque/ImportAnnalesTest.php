<?php

namespace Tests\Feature\Banque;

use App\Models\CompetencyNode;
use App\Models\Question;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ImportAnnales;
use App\Services\QuestionIntegrityChecker;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * L'import des annales — lot CRMEF-2, phase 3.
 *
 * LE CŒUR DU LOT est `test_une_annale_importee_ne_peut_pas_etre_publiee` : une
 * question sans corrigé officiel ne doit pas POUVOIR être servie à un candidat,
 * par construction et non par consigne.
 */
class ImportAnnalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
    }

    // ═════════════════════════════ la canonisation des numéros

    /**
     * LE MODE DE DÉFAILLANCE SILENCIEUSE, tenu par un test.
     *
     * Le corpus titre `Q1`, `Q 1` et `Q01` selon les blocs. Si les deux côtés de
     * la jointure ne canonisent pas de la même façon, rien ne lève : l'import
     * ne trouve simplement aucun classement, sur deux blocs entiers, et les
     * rejette tous en silence apparent.
     */
    public function test_les_trois_formes_de_numero_du_corpus_se_canonisent_pareil(): void
    {
        $this->assertSame('Q1', ImportAnnales::canoniser('Q1'));
        $this->assertSame('Q1', ImportAnnales::canoniser('Q 1'));
        $this->assertSame('Q1', ImportAnnales::canoniser('Q01'));
        $this->assertSame('Q110', ImportAnnales::canoniser('Q 110'));

        /* Et ce qui n'est pas un numéro n'en devient pas un. */
        $this->assertNull(ImportAnnales::canoniser('En-tête officiel'));
        $this->assertNull(ImportAnnales::canoniser('Q80 à Q84'));
    }

    // ═══════════════════════════════════ ce que l'import écrit

    public function test_une_annale_entre_en_brouillon_sans_aucune_bonne_reponse(): void
    {
        $question = $this->importerUne();

        $this->assertSame('draft', $question->status);
        $this->assertSame('imported', $question->authoring);
        $this->assertFalse($question->eligible_for_diagnostic);
        $this->assertFalse($question->eligible_for_simulation);

        $this->assertSame(5, $question->options()->count());
        $this->assertSame(
            0,
            $question->options()->where('is_correct', true)->count(),
            'Le corpus n’a aucun corrigé officiel : marquer une option serait fabriquer une vérité.'
        );

        /* Aucune justification, aucune cause : c'est ce que le relecteur devra
         * écrire, et c'est ce qui bloque la publication d'ici là. */
        $this->assertSame(5, $question->options()->whereNull('rationale')->count());
        $this->assertSame(5, $question->options()->whereNull('cause')->count());
    }

    /**
     * ═══════════════════════════════════════════════════════════════════════
     * LE CŒUR DU LOT.
     *
     * Une annale importée ne peut pas être publiée, et trois refus indépendants
     * l'en empêchent. On les éprouve TOUS, parce qu'un seul qui tiendrait par
     * accident ne prouverait rien : si demain quelqu'un renseigne une bonne
     * réponse à la main, les deux autres doivent encore refuser.
     */
    public function test_une_annale_importee_ne_peut_pas_etre_publiee(): void
    {
        $question = $this->importerUne();
        $checker = app(QuestionIntegrityChecker::class);

        $this->assertFalse($checker->canBePublished($question));

        $defauts = implode(' | ', $checker->publicationIssues($question));

        $this->assertStringContainsString('exactement une bonne réponse', $defauts);
        $this->assertStringContainsString("n'a pas de justification", $defauts);

        /* Et pour le diagnostic, la cause de chaque distracteur s'ajoute. */
        $this->assertStringContainsString(
            "n'a pas de cause d'erreur",
            implode(' | ', $checker->diagnosticIssues($question))
        );
    }

    /**
     * LA GARANTIE DE DERNIER RECOURS. Le service peut être contourné — un
     * `UPDATE` direct, une commande d'administration, une migration maladroite.
     * PostgreSQL, non.
     */
    public function test_la_base_refuse_elle_meme_de_publier_une_annale_importee(): void
    {
        $question = $this->importerUne();

        /* On force l'état antérieur exigé par le déclencheur : sans cela il
         * refuserait pour la transition, et non pour ce qu'on veut prouver. */
        $valideur = User::firstOrCreate(
            ['email' => 'valideur.annales@naja7i.ma'],
            ['password' => 'Corpus-2026!', 'locale' => 'fr', 'status' => 'active']
        );

        DB::statement(
            "UPDATE questions SET status = 'pedagogically_validated', validator_id = ? WHERE id = ?",
            [$valideur->id, $question->id]
        );

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('exactement une bonne réponse est attendue');

        DB::statement(
            "UPDATE questions SET status = 'published', published_at = now() WHERE id = ?",
            [$question->id]
        );
    }

    /**
     * UNE JUSTIFICATION NULLE VAUT UNE JUSTIFICATION VIDE.
     *
     * `question_options.rationale` est devenue nullable pour laisser entrer les
     * annales. Le déclencheur comptait `length(btrim(rationale)) = 0`, ce qui
     * est NUL sur une valeur nulle — et un `FILTER` n'incrémente pas sur NULL.
     * Sans la correction, une option sans AUCUNE justification serait passée là
     * où une justification VIDE était refusée.
     */
    public function test_une_justification_nulle_bloque_la_publication_comme_une_vide(): void
    {
        $question = $this->importerUne();

        /* On lève les deux autres verrous pour n'éprouver que celui-ci. */
        $premiere = $question->options()->orderBy('position')->first();
        $premiere->update(['is_correct' => true, 'rationale' => 'La bonne réponse, justifiée.']);

        $valideur = User::firstOrCreate(
            ['email' => 'valideur.justif@naja7i.ma'],
            ['password' => 'Corpus-2026!', 'locale' => 'fr', 'status' => 'active']
        );

        DB::statement(
            "UPDATE questions SET status = 'pedagogically_validated', validator_id = ? WHERE id = ?",
            [$valideur->id, $question->id]
        );

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('option(s) sans justification');

        DB::statement(
            "UPDATE questions SET status = 'published', published_at = now() WHERE id = ?",
            [$question->id]
        );
    }

    /**
     * L'IMPORT NE POURRAIT PAS SE DÉCLARER ÉLIGIBLE MÊME S'IL LE VOULAIT.
     *
     * Découvert par mutation : rendre l'import éligible au diagnostic ne fait
     * rougir AUCUN test — parce que `eligible_for_diagnostic` n'est pas
     * assignable en masse. `Question::$fillable` exclut les champs de
     * transition, et `$attributes` les pose à faux : « une question ne peut pas
     * naître publiée ».
     *
     * La mutation était donc inoffensive, et c'est une bonne nouvelle mal
     * documentée. On l'écrit ici : la garde ne tient pas à la prudence de
     * l'importateur, elle tient au modèle. Un futur import écrit par quelqu'un
     * d'autre en hérite sans le savoir.
     */
    public function test_le_modele_refuse_qu_un_import_se_declare_eligible(): void
    {
        $noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        $question = Question::create([
            'exam_id' => $noeud->exam_id,
            'competency_node_id' => $noeud->id,
            'locale' => 'ar',
            'stem' => 'Énoncé d’essai.',
            'authoring' => 'imported',
            /* Demandés explicitement, et ignorés : ces trois-là ne sont pas
             * assignables en masse. */
            'status' => 'published',
            'eligible_for_diagnostic' => true,
            'eligible_for_simulation' => true,
        ]);

        $this->assertSame('draft', $question->status);
        $this->assertFalse($question->eligible_for_diagnostic);
        $this->assertFalse($question->eligible_for_simulation);
    }

    // ═══════════════════════════════════════ traçabilité et rejeu

    /**
     * LES MARQUES MANUSCRITES SONT UNE TRACE, JAMAIS UNE RÉPONSE.
     *
     * Le corpus les qualifie lui-même : anonymes, non officielles, et
     * « contradictoires ou multiples sur des dizaines de questions ». Elles vont
     * en note, et la note dit ce qu'elles ne sont pas.
     */
    public function test_la_marque_manuscrite_va_en_note_et_jamais_dans_is_correct(): void
    {
        $question = $this->importerUne(marque: 'A et D (marques manuscrites, non officielles)');

        $this->assertSame(0, $question->options()->where('is_correct', true)->count());

        $this->assertStringContainsString('MARQUE MANUSCRITE', $question->import_note);
        $this->assertStringContainsString('A et D', $question->import_note);
        $this->assertStringContainsString('plusieurs marques', $question->import_note);
        $this->assertStringContainsString('Ce n’est pas une réponse', $question->import_note);
    }

    public function test_la_note_porte_la_fiabilite_de_lecture(): void
    {
        $question = $this->importerUne(fiabilite: 'partiellement illisible (page floue)');

        $this->assertStringContainsString('Fiabilité de lecture : partiellement illisible', $question->import_note);
        $this->assertStringContainsString('Page du scan : 7', $question->import_note);
    }

    /** REJOUER L'IMPORT NE CRÉE AUCUN DOUBLON — patron des cinq bloquants. */
    public function test_l_import_est_rejouable_sans_doublon(): void
    {
        $premier = $this->executer();
        $this->assertSame(1, $premier['importees']);

        $second = $this->executer();
        $this->assertSame(0, $second['importees']);
        $this->assertSame(1, $second['inchangees']);

        $this->assertSame(1, Question::whereNotNull('import_ref')->count());
    }

    /** L'INDEX UNIQUE EST LA GARANTIE, PAS LE `SELECT` PRÉALABLE. */
    public function test_la_base_refuse_deux_annales_de_meme_empreinte(): void
    {
        $question = $this->importerUne();

        $this->expectException(QueryException::class);

        DB::statement(
            'INSERT INTO questions (uuid, exam_id, competency_node_id, locale, stem, status, authoring, import_ref, created_at, updated_at)
             SELECT gen_random_uuid(), exam_id, competency_node_id, locale, stem, status, authoring, import_ref, now(), now()
             FROM questions WHERE id = ?',
            [$question->id]
        );
    }

    /** Une question sans domaine de rattachement est REJETÉE, pas rattachée au hasard. */
    public function test_une_question_non_classee_est_rejetee_avec_son_motif(): void
    {
        $rapport = $this->executer(classee: false);

        $this->assertSame(0, $rapport['importees']);
        $this->assertSame(1, $rapport['rejetees']);
        $this->assertStringContainsString(
            'aucun domaine de rattachement',
            $rapport['rejets'][0]['motif']
        );
    }

    // ═══════════════════════════════════════════════════════ fabrique

    private function executer(bool $classee = true, string $marque = 'non indiquée', string $fiabilite = 'claire'): array
    {
        $noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        $corpus = <<<MD
            ## C. Voie d'essai

            ### علوم التربية — Q61–Q120

            *Source : `BLOC_ESSAI` — 1 question transcrite.*

            #### En-tête officiel
            - Intitulé exact de l'épreuve (arabe, tel qu'imprimé) : اختبار في علوم التربية
            - Session (دورة) telle qu'imprimée : دورة نونبر 2025
            - Autorité émettrice imprimée sur le document : المركز الوطني للامتحانات المدرسية
            - Filigrane / mention de site web : www.educaprof.com
            - Un corrigé est-il fourni dans le document ? non — aucune clé de correction.

            #### Questions

            ##### Q61
            **Énoncé :** بم تتميز مرحلة الكمون عند س. فرويد؟
            **Options :**
            - A. الاضطراب العاطفي
            - B. الاستقرار النفسي
            - C. البحث عن الهوية
            - D. النضج المتأخر
            - E. جميع الاختيارات المقترحة خاطئة
            **Réponse indiquée dans le document :** {$marque}
            **Page :** 7
            **Fiabilité :** {$fiabilite}

            ## D. Suite
            MD;

        $classement = ['BLOC_ESSAI|Q61' => [
            'code_noeud' => $classee ? $noeud->code : '',
            'confiance' => 'haute',
            'motif' => $classee ? 'Stade psychosexuel : psychologie du développement.' : 'Hors des six nœuds.',
        ]];

        return app(ImportAnnales::class)->importer($corpus, $classement, 'BLOC_ESSAI');
    }

    private function importerUne(string $marque = 'non indiquée', string $fiabilite = 'claire'): Question
    {
        $this->executer(marque: $marque, fiabilite: $fiabilite);

        /* La source du sujet existe, avec son statut réel : une annale, jamais
         * un descriptif officiel, et non vérifiée. */
        $source = Source::where('code', 'SRC-ANNALE-BLOC-ESSAI')->firstOrFail();
        $this->assertSame('annale', $source->kind);
        $this->assertNull($source->verified_at);

        return Question::whereNotNull('import_ref')->with('options')->firstOrFail();
    }
}
