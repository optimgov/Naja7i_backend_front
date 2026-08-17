<?php

namespace Tests\Feature\Correctifs;

use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CouvertureBanque;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DET-71 — test de NON-RÉGRESSION : la frontière de seconde ne fait plus
 * disparaître une question publiée.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE MÉCANISME, MESURÉ PUIS REPRODUIT À VOLONTÉ
 *
 * `CouvertureBanque` filtre `q.published_at <= now()`. Deux horloges s'y
 * rencontrent : `published_at` est écrit par PHP **tronqué à la seconde**
 * (mesuré : `.000000`), tandis que `now()` vaut `transaction_timestamp()` —
 * figé à l'ouverture de la transaction du test, avec ses microsecondes.
 *
 * Dans la même seconde, la troncature protège : elle recule `published_at`
 * jusqu'au début de la seconde, donc avant `now()`. Mais si une frontière de
 * seconde passe ENTRE l'ouverture de la transaction et la publication, la
 * troncature ne recule plus assez : `published_at` vaut la seconde SUIVANTE et
 * devient FUTUR pour la base. La question disparaît du décompte, et `severity`
 * bascule de `no_sibling` à `none` — l'échec exact du 13 août.
 *
 * La probabilité est la fraction de seconde consommée entre `setUp` et la
 * publication : « vert seul, rouge en suite » s'explique par ce DÉLAI, pas par
 * l'ordre.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * IL SUIT LE CHEMIN PRODUIT, et c'est ce qui le rend probant.
 *
 * Une première écriture affirmait le défaut sur une requête SQL écrite dans le
 * test lui-même. Elle décrivait bien le mécanisme — et elle n'aurait PAS rougi
 * à la correction, puisque corriger le service ne change rien à une requête que
 * le test se donne à lui-même. Genre 3 du bestiaire, évité de peu.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * IL A D'ABORD ÉTÉ UN TEST DE CARACTÉRISATION, et c'est ce qui l'a rendu utile.
 *
 * Il affirmait le défaut — `severity` valant `none` — et il a ROUGI au moment
 * de la correction, rendant `no_sibling`. C'était la preuve demandée : sans
 * elle, on aurait retiré une condition en espérant qu'elle soit la bonne.
 *
 * Il est conservé retourné plutôt que supprimé : la frontière de seconde reste
 * franchie à chaque exécution, et toute réintroduction d'une comparaison
 * d'horloges dans ce chemin le fera rougir de nouveau.
 */
class SondeHorlogeCouvertureTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_question_publiee_apres_une_frontiere_de_seconde_disparait_du_plan(): void
    {
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        /*
         * ON FRANCHIT LA FRONTIÈRE DE SECONDE AVANT DE PUBLIER.
         *
         * `now()` de PostgreSQL est déjà figé — la transaction est ouverte
         * depuis `setUp`. On attend la seconde suivante : tout `published_at`
         * écrit ensuite sera tronqué à une seconde POSTÉRIEURE à ce `now()`.
         */
        $fige = new \DateTimeImmutable(DB::selectOne('SELECT now() AS n')->n);
        usleep(1_000_000 - (int) $fige->format('u') + 50_000);

        $question = $this->publier($epreuve, $noeud);
        $publie = Question::find($question->id)->published_at;

        fwrite(STDERR, "\n═══ DET-71 — caractérisation ═══\n");
        fwrite(STDERR, '  PG now() figé à l’ouverture : '.$fige->format('H:i:s.u')."\n");
        fwrite(STDERR, '  published_at écrit          : '.$publie->format('H:i:s.u')
            ."  (tronqué à la seconde)\n");
        fwrite(STDERR, '  published_at est-il futur ? : '
            .($publie->getTimestamp() > (int) $fige->format('U') ? 'OUI' : 'non')."\n");

        /* Un rendez-vous de révision fait exister la ligne au plan : le plan
         * recense les couples ATTENDUS par un candidat, pas la banque entière. */
        $this->creerRendezVous($epreuve, $noeud);

        $ligne = app(CouvertureBanque::class)
            ->trous($epreuve)
            ->first(fn ($l) => $l['cause'] === 'confusion_notions'
                && $l['competency']['code'] === $noeud->code);

        $severite = $ligne['coverage']['fr']['severity'] ?? 'ligne absente';

        fwrite(STDERR, "  severity rendu par le plan  : {$severite}"
            .($severite === 'none' ? "  ← ELLE A DISPARU : DET-71 est revenue\n\n" : "  (comptée)\n\n"));

        $this->assertSame(
            'no_sibling',
            $severite,
            'DET-71 est revenue : une question publiée après une frontière de seconde a '
            .'disparu du plan de rédaction. Une comparaison d’horloges a dû être '
            .'réintroduite sur ce chemin — `status = published` doit y suffire.'
        );
    }

    private function publier(Exam $epreuve, CompetencyNode $noeud): Question
    {
        $personne = fn (string $e) => User::firstOrCreate(
            ['email' => $e],
            ['password' => 'Sonde-2026!', 'locale' => 'fr', 'status' => 'active']
        );

        $remediation = Remediation::firstOrCreate(
            ['competency_node_id' => $noeud->id, 'locale' => 'fr'],
            ['title' => 'R', 'content' => 'x', 'estimated_minutes' => 5, 'status' => 'published']
        );

        $question = Question::create([
            'exam_id' => $epreuve->id,
            'competency_node_id' => $noeud->id,
            'locale' => 'fr',
            'sibling_group' => (string) Str::uuid7(),
            'stem' => 'Énoncé de caractérisation.',
            'explanation' => 'Justification.',
            'remediation_id' => $remediation->id,
            'author_id' => $personne('det71.auteur@naja7i.ma')->id,
        ]);

        foreach ([
            ['A', false, 'confusion_notions'],
            ['B', true, null],
            ['C', false, 'lecture_enonce'],
            ['D', false, 'connaissance_absente'],
            ['Aucune des propositions précédentes', false, 'indetermine'],
        ] as $p => [$c, $juste, $cause]) {
            QuestionOption::create([
                'question_id' => $question->id, 'position' => $p + 1,
                'content' => $c, 'is_correct' => $juste, 'rationale' => 'r', 'cause' => $cause,
            ]);
        }

        $question->contentSources()->attach(
            Source::where('code', 'SRC-CRMEF-2025-SE')->firstOrFail()->id,
            ['verification' => 'verified']
        );

        $valideur = $personne('det71.valideur@naja7i.ma');
        $service = app(QuestionTransitionService::class);
        $service->submitForReview($question);
        $service->markReviewed($question->fresh(), $this->relecteurDeControle());
        $service->validate($question->fresh(), $valideur);
        $service->publish($question->fresh(), forDiagnostic: true);

        return $question->fresh();
    }

    private function creerRendezVous(Exam $epreuve, CompetencyNode $noeud): void
    {
        $candidat = User::firstOrCreate(
            ['email' => 'det71.candidat@naja7i.ma'],
            ['password' => 'Sonde-2026!', 'locale' => 'fr', 'status' => 'active']
        );

        DB::table('review_schedules')->insert([
            'uuid' => (string) Str::uuid7(),
            'tenant_id' => Tenant::where('kind', 'platform')->value('id'),
            'user_id' => $candidat->id,
            'exam_id' => $epreuve->id,
            'competency_node_id' => $noeud->id,
            'cause' => 'confusion_notions',
            'palier' => 1,
            'due_on' => now()->toDateString(),
            'consecutive_sure' => 0,
            'blind_error' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
