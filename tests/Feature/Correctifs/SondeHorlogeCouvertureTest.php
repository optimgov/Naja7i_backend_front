<?php

namespace Tests\Feature\Correctifs;

use App\Models\CompetencyNode;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Remediation;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QuestionTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SONDE — une MESURE, pas un test de produit (DET-71).
 *
 * `CouvertureBanque` filtre `q.published_at <= now()`. Deux horloges se
 * rencontrent là : `published_at` est écrit par PHP, `now()` est évalué par
 * PostgreSQL. La question que cette sonde tranche est simple, et mon
 * raisonnement seul ne pouvait pas y répondre : **lequel des trois horodatages
 * SQL `now()` vaut-il réellement ici** — `transaction_timestamp()`, figé à
 * l'ouverture de la transaction, `statement_timestamp()` ou `clock_timestamp()` ?
 *
 * Si c'est le premier, une question publiée PENDANT le test porte une date
 * future au regard de la base, et le filtre l'exclut. Ce modèle prédisait un
 * échec systématique — or le test passe 40 fois sur 40 en isolation. Un modèle
 * qui n'explique pas le vert n'explique pas le rouge : on mesure.
 *
 * ELLE EST DEVENUE UN TEST DE CARACTÉRISATION : elle affirme le défaut tel
 * qu'il est. Sa mise au rouge signalera que DET-71 a été corrigée, et
 * commandera sa suppression.
 */
class SondeHorlogeCouvertureTest extends TestCase
{
    use RefreshDatabase;

    public function test_sonde_les_trois_horloges_et_la_date_reellement_ecrite(): void
    {
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $t = DB::selectOne('SELECT now() AS n, transaction_timestamp() AS tt,
                                   statement_timestamp() AS st, clock_timestamp() AS ct');

        $lire = fn (string $v) => (new \DateTimeImmutable($v))->format('H:i:s.u');
        $ms = fn (string $v) => (float) (new \DateTimeImmutable($v))->format('U.u') * 1000;

        $php = now();

        fwrite(STDERR, "\n═══ SONDE DET-71 ═══\n");
        fwrite(STDERR, '  transaction ouverte ?         : '.(DB::transactionLevel() > 0 ? 'oui, niveau '.DB::transactionLevel() : 'NON')."\n");
        fwrite(STDERR, '  Carbon::hasTestNow()          : '.(Carbon::hasTestNow() ? 'OUI — le temps est figé' : 'non')."\n\n");
        fwrite(STDERR, '  PG now()                      : '.$lire($t->n)."\n");
        fwrite(STDERR, '  PG transaction_timestamp()    : '.$lire($t->tt)."\n");
        fwrite(STDERR, '  PG statement_timestamp()      : '.$lire($t->st)."\n");
        fwrite(STDERR, '  PG clock_timestamp()          : '.$lire($t->ct)."\n");
        fwrite(STDERR, '  PHP now()                     : '.$php->format('H:i:s.u')."\n\n");
        fwrite(STDERR, '  now() === transaction_timestamp() ? : '
            .(abs($ms($t->n) - $ms($t->tt)) < 0.001 ? 'OUI — figé à l’ouverture' : 'non')."\n");
        fwrite(STDERR, '  écart clock_timestamp − now() : '
            .round($ms($t->ct) - $ms($t->n), 1)." ms\n");

        /* ─── Le cas réel : on publie, puis on demande à la base si elle voit. */
        $question = $this->publierUne();
        $publie = Question::find($question->id)->published_at;

        $vu = DB::selectOne(
            'SELECT count(*) AS n FROM questions
             WHERE id = ? AND published_at IS NOT NULL AND published_at <= now()',
            [$question->id]
        )->n;

        $vuHorloge = DB::selectOne(
            'SELECT count(*) AS n FROM questions
             WHERE id = ? AND published_at IS NOT NULL AND published_at <= clock_timestamp()',
            [$question->id]
        )->n;

        fwrite(STDERR, "\n  published_at réellement écrit : ".$publie->format('H:i:s.u')."\n");
        fwrite(STDERR, '  écart published_at − now()    : '
            .round($ms($publie->toIso8601String()) - $ms($t->n), 1)." ms\n");
        fwrite(STDERR, "  comptée par `<= now()`            : {$vu}"
            .($vu === 0 ? "  ← C’EST LE MÉCANISME\n" : "\n"));
        fwrite(STDERR, "  comptée par `<= clock_timestamp()` : {$vuHorloge}\n\n");

        /*
         * ─── LA DÉMONSTRATION : on force la frontière de seconde ───
         *
         * `published_at` est tronqué à la seconde ; `now()` est figé à
         * l'ouverture de la transaction, avec ses microsecondes. Tant que les
         * deux tombent dans la MÊME seconde, la troncature joue en faveur du
         * test : elle recule `published_at` jusqu'à `.000000`, donc avant
         * `now()`.
         *
         * Mais si une frontière de seconde passe ENTRE l'ouverture de la
         * transaction et la publication, la troncature ne recule plus assez :
         * `published_at` vaut la seconde SUIVANTE, et devient FUTUR au regard
         * d'un `now()` resté dans la précédente. La ligne disparaît du filtre.
         *
         * On attend donc la fin de la seconde en cours avant de publier.
         */
        $ouverture = $ms($t->n);

        usleep((int) ((1000 - ($ouverture - floor($ouverture / 1000) * 1000)) * 1000) + 20000);

        $tardive = $this->publierUne('sonde.tardive');
        $publieTard = Question::find($tardive->id)->published_at;

        $vuTard = DB::selectOne(
            'SELECT count(*) AS n FROM questions
             WHERE id = ? AND published_at IS NOT NULL AND published_at <= now()',
            [$tardive->id]
        )->n;

        fwrite(STDERR, "  ─── après une frontière de seconde ───\n");
        fwrite(STDERR, '  published_at                  : '.$publieTard->format('H:i:s.u')."\n");
        fwrite(STDERR, '  PG now() (toujours figé)      : '.$lire($t->n)."\n");
        fwrite(STDERR, "  comptée par `<= now()`            : {$vuTard}"
            .($vuTard === 0 ? "  ← REPRODUIT : la ligne est FUTURE pour la base\n\n" : "\n\n"));

        /*
         * ON AFFIRME LE DÉFAUT TEL QU'IL EST AUJOURD'HUI — test de
         * caractérisation, pas test de produit.
         *
         * Le jour où DET-71 sera corrigée — en comparant à `clock_timestamp()`,
         * ou en retirant une condition que `status = 'published'` rend déjà
         * vraie — CE TEST ROUGIRA. C'est voulu : il faudra alors le supprimer,
         * et son rouge sera la preuve que la correction a mordu.
         */
        $this->assertSame(
            0,
            (int) $vuTard,
            'DET-71 semble corrigée : une question publiée après une frontière de seconde '
            .'est désormais comptée. Supprimez ce test de caractérisation et fermez la dette.'
        );
    }

    private function publierUne(string $prefixe = 'sonde'): Question
    {
        $noeud = CompetencyNode::where('code', 'SE-PSY-DEV')->firstOrFail();

        $remediation = Remediation::firstOrCreate(
            ['competency_node_id' => $noeud->id, 'locale' => 'fr'],
            ['title' => 'R', 'content' => 'x', 'estimated_minutes' => 5, 'status' => 'published']
        );

        $personne = fn (string $e) => User::firstOrCreate(
            ['email' => $e],
            ['password' => 'Sonde-2026!', 'locale' => 'fr', 'status' => 'active']
        );

        $question = Question::create([
            'exam_id' => $noeud->exam_id,
            'competency_node_id' => $noeud->id,
            'locale' => 'fr',
            'sibling_group' => (string) Str::uuid7(),
            'stem' => 'Énoncé de sonde.',
            'explanation' => 'Justification.',
            'remediation_id' => $remediation->id,
            'author_id' => $personne($prefixe.'.auteur@naja7i.ma')->id,
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

        $valideur = $personne($prefixe.'.valideur@naja7i.ma');
        $service = app(QuestionTransitionService::class);
        $service->submitForReview($question);
        $service->markReviewed($question->fresh(), $valideur);
        $service->validate($question->fresh(), $valideur);
        $service->publish($question->fresh(), forDiagnostic: true);

        return $question->fresh();
    }
}
