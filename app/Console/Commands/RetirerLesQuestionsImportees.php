<?php

namespace App\Console\Commands;

use App\Models\Exam;
use App\Models\Question;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan naja7i:retirer-les-questions-importees` — défaire un import
 * d'annales pour que le corpus repasse par la zone de préparation.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI ELLE EXISTE
 *
 * `crmef:importer-annales` écrit directement dans `questions`, en sautant la
 * zone de préparation. M-019 a posé 53 brouillons par ce chemin. La décision
 * du propriétaire du 24 août est de les réimporter de zéro pour qu'ils suivent
 * le même parcours que le reste du corpus — un seul chemin, pas deux.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ELLE SUPPRIME, DONC ELLE SE MÉFIE D'ELLE-MÊME
 *
 * Quatre gardes, dans cet ordre, et chacune refuse tout le lot plutôt que
 * d'en retirer une partie :
 *
 *   1. `--env` nommé, comme toute commande qui écrit.
 *   2. Rien qui ne soit `authoring = imported` ET `status = draft`. Une
 *      question écrite à la main ou publiée n'appartient pas à un import.
 *   3. Rien qui ait servi : une question citée par un `attempt_item` a été
 *      posée à quelqu'un. La supprimer effacerait la trace de sa réponse.
 *   4. Rien qui soit déjà tenu par la zone de préparation
 *      (`prepared_questions.question_id`) : la ligne aurait été transférée, et
 *      c'est la chaîne éditoriale qui en répond, pas une commande.
 *
 * Les gardes 3 et 4 doublent des contraintes que la base porte déjà en
 * `restrictOnDelete`. C'est voulu : une contrainte produit une exception
 * illisible en fin de course, la garde produit un refus qui NOMME ce qui
 * bloque, avant d'avoir rien tenté.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ELLE N'AGIT PAS SANS QU'ON LE LUI DISE DEUX FOIS
 *
 * Sans `--confirmer`, elle annonce et s'arrête. C'est l'inverse de
 * `--dry-run` : ici le défaut est de ne rien faire, parce que le geste ne se
 * défait pas.
 */
class RetirerLesQuestionsImportees extends Command
{
    protected $signature = 'naja7i:retirer-les-questions-importees
                            {--epreuve= : Limiter à une épreuve (code, ex. CRMEF-SE-2025)}
                            {--confirmer : Exécuter réellement — sans ce drapeau, la commande annonce et s’arrête}';

    protected $description = 'Retire les brouillons issus d’un import d’annales, pour qu’ils repassent par la zone de préparation';

    public function handle(TenantContext $contexte): int
    {
        if (! $this->assertEnvironnementNomme()) {
            return self::FAILURE;
        }

        $contexte->set(Tenant::where('kind', 'platform')->firstOrFail());

        $epreuve = null;
        if (filled($this->option('epreuve'))) {
            $epreuve = Exam::where('code', (string) $this->option('epreuve'))->first();

            if ($epreuve === null) {
                $this->error('epreuve_inconnue='.$this->option('epreuve'));
                $this->line('Épreuves : '.Exam::query()->orderBy('code')->pluck('code')->implode(', ').'.');

                return self::FAILURE;
            }
        }

        /* GARDE 2 — le périmètre est défini par ce qu'on retire, pas par ce
         * qu'on veut retirer. Tout ce qui sort de « importé et brouillon »
         * n'entre jamais dans la sélection. */
        $visees = Question::query()
            ->where('authoring', 'imported')
            ->where('status', 'draft')
            ->when($epreuve !== null, fn ($q) => $q->where('exam_id', $epreuve->id));

        $total = (clone $visees)->count();

        $this->line('environnement='.app()->environment());
        $this->line('epreuve='.($epreuve?->code ?? '(toutes)'));
        $this->line('visees='.$total.' (authoring=imported, status=draft)');

        if ($total === 0) {
            $this->newLine();
            $this->info('Rien à retirer. La base ne porte aucun brouillon importé dans ce périmètre.');

            return self::SUCCESS;
        }

        $ids = (clone $visees)->pluck('id');

        $servies = DB::table('attempt_items')->whereIn('question_id', $ids)->distinct()->count('question_id');
        $transferees = DB::table('prepared_questions')->whereIn('question_id', $ids)->count();
        $options = DB::table('question_options')->whereIn('question_id', $ids)->count();
        $sources = DB::table('question_sources')->whereIn('question_id', $ids)->count();

        $this->line('  options_liees='.$options.' (suppression en cascade)');
        $this->line('  sources_liees='.$sources.' (suppression en cascade)');
        $this->line('  deja_servies='.$servies);
        $this->line('  tenues_par_la_preparation='.$transferees);

        /* GARDES 3 et 4 — tout le lot refuse, jamais une partie. Retirer les
         * unes en laissant les autres produirait un état que personne n'a
         * décidé, et qu'aucun rapport ne décrirait. */
        if ($servies > 0 || $transferees > 0) {
            $this->newLine();
            $this->error('Refusé : le lot contient des questions qu’une commande ne doit pas retirer.');

            if ($servies > 0) {
                $this->line('  '.$servies.' ont déjà été posées à quelqu’un (`attempt_items`). '
                    .'Les supprimer effacerait la trace de réponses réelles.');
            }

            if ($transferees > 0) {
                $this->line('  '.$transferees.' sont tenues par la zone de préparation. '
                    .'C’est la chaîne éditoriale qui en répond.');
            }

            return self::FAILURE;
        }

        $parNoeud = (clone $visees)
            ->join('competency_nodes as n', 'n.id', '=', 'questions.competency_node_id')
            ->selectRaw('n.code, count(*) as n')->groupBy('n.code')->orderByDesc('n')->pluck('n', 'n.code');

        $this->newLine();
        $this->line('Répartition sur les nœuds :');
        foreach ($parNoeud as $code => $n) {
            $this->line(sprintf('  %-18s %3d', $code, $n));
        }

        if (! $this->option('confirmer')) {
            $this->newLine();
            $this->warn('Rien n’a été supprimé.');
            $this->line('Ce geste ne se défait pas. Relancez avec --confirmer, sauvegarde prise.');

            return self::SUCCESS;
        }

        $retirees = DB::transaction(function () use ($ids): int {
            /* `question_options` et `question_sources` partent en cascade
             * (`cascadeOnDelete`), `review_schedules` se dénoue
             * (`nullOnDelete`). Rien d'autre ne pend de ces lignes — les deux
             * seules références restrictives sont celles que les gardes 3 et 4
             * viennent d'écarter. */
            return Question::whereIn('id', $ids)->delete();
        });

        Log::warning('Brouillons importés retirés de la banque', [
            'environnement' => app()->environment(),
            'epreuve' => $epreuve?->code ?? 'toutes',
            'retirees' => $retirees,
        ]);

        $this->newLine();
        $this->info('Retiré : '.$retirees.' question(s).');
        $this->line('restantes_importees='.Question::where('authoring', 'imported')->count());
        $this->line('Le corpus peut maintenant repasser par `naja7i:importer-le-corpus-qcm`.');

        return self::SUCCESS;
    }

    private function assertEnvironnementNomme(): bool
    {
        if (filled($this->option('env'))) {
            return true;
        }

        $this->error('env_absent=1');
        $this->line(
            'Nommez l’environnement : --env=local, --env=staging, --env=production. '
            .'Cette commande SUPPRIME des lignes ; elle ne devine pas où.'
        );

        return false;
    }
}
