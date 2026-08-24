<?php

namespace App\Console\Commands;

use App\Enums\QuestionPreparationBatchStatus;
use App\Models\Exam;
use App\Models\PreparedQuestion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QuestionPreparationService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `php artisan naja7i:importer-le-corpus-qcm` — le corpus entre en zone de
 * préparation, rangé par ARBRE et jamais qualifié.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE CHEMIN DU FICHIER EST UN ARGUMENT, ET C'EST DÉLIBÉRÉ (DET-100)
 *
 * `crmef:importer-annales` code ses chemins sous `base_path('docs/corpus/…')`,
 * et `.dockerignore` exclut `docs` : sur une machine déployée elle refuse, et
 * il a fallu porter les fichiers à la main dans le conteneur (M-019). Cette
 * commande-ci naît sans ce défaut — elle ne sait rien d'un emplacement, on lui
 * dit lequel.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * L'ARBRE, PAS LE NŒUD — la distinction que tout ce lot sert
 *
 * L'import range chaque ligne sous son ÉPREUVE (`exam_id`) et laisse
 * `competency_node_id` NUL. Le pré-classement du 15 août — 213 questions avec
 * leur `domaine_code`, leur confiance et leur motif — est recopié dans
 * `provisional`, où il se lit comme ce qu'il est : une aide, produite par un
 * script, que personne n'a validée.
 *
 * L'écrire dans `competency_node_id` en ferait le travail d'un expert qui n'a
 * rien fait. Les Instructions du 22 août sont explicites : « mon classement,
 * testé par double lecture mais NON VALIDÉ PAR UN EXPERT ».
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QU'ELLE NE FAIT JAMAIS
 *
 * Elle ne verse aucune `suggestion_reponse` dans `confirmed_answer`. Ce sont
 * 701 coches manuscrites anonymes relevées sur un scan, dont dix se
 * contredisent sur le papier. `prepare()` les range dans `proposed_answer`,
 * et c'est le seul endroit où elles ont le droit d'être.
 */
class ImporterLeCorpusQcm extends Command
{
    protected $signature = 'naja7i:importer-le-corpus-qcm
                            {--fichier= : Chemin du JSON du corpus}
                            {--famille= : Famille à importer (ex. « Sciences de l’éducation »)}
                            {--epreuve= : Code de l’épreuve où ranger le lot (ex. CRMEF-SE-2025)}
                            {--acteur= : Adresse du compte de personnel qui porte le lot}
                            {--simulation : Compter et rapporter, sans rien écrire}';

    protected $description = 'Importe une famille du corpus QCM en zone de préparation, rangée par épreuve';

    /** Les faits de source — lecture seule, jamais modifiables (Instructions §2A). */
    private const FAITS_DE_SOURCE = [
        'id', 'sujet', 'numero', 'numero_int', 'voie', 'session', 'famille',
        'discipline', 'enonce', 'options', 'nb_options', 'page_source',
        'fiabilite_lecture', 'domaine_imprime', 'doublon_de',
        'suggestion_reponse', 'suggestion_origine', 'suggestion_ambigue', 'statut',
    ];

    /** Les métadonnées posées d'autorité, toutes révisables (Instructions §2C). */
    private const PROVISOIRES = [
        'difficulte', 'temps_s', 'priorite', 'valeurs_par_defaut',
        'domaine_code', 'domaine_confiance', 'domaine_motif', 'arbre_cible',
    ];

    public function handle(QuestionPreparationService $preparation, TenantContext $contexte): int
    {
        if (! $this->assertEnvironnementNomme()) {
            return self::FAILURE;
        }

        $contexte->set(Tenant::where('kind', 'platform')->firstOrFail());

        $chemin = (string) $this->option('fichier');
        $famille = (string) $this->option('famille');

        if ($chemin === '' || ! is_readable($chemin)) {
            $this->error('fichier_illisible='.($chemin === '' ? '(absent)' : $chemin));
            $this->line('Nommez le corpus : --fichier=/chemin/naja7i_qcm_a_valider.json');

            return self::FAILURE;
        }

        if ($famille === '') {
            $this->error('famille_absente=1');
            $this->line('Nommez la famille : --famille="Sciences de l’éducation"');

            return self::FAILURE;
        }

        $epreuve = Exam::where('code', (string) $this->option('epreuve'))->first();
        if ($epreuve === null) {
            $this->error('epreuve_inconnue='.($this->option('epreuve') ?: '(absente)'));
            $this->line('Épreuves modélisées : '.Exam::query()->orderBy('code')->pluck('code')->implode(', ').'.');

            return self::FAILURE;
        }

        $acteur = User::where('email', mb_strtolower(trim((string) $this->option('acteur'))))->first();
        if ($acteur === null) {
            $this->error('acteur_inconnu='.($this->option('acteur') ?: '(absent)'));
            $this->line('Le lot porte le nom de qui le lance : --acteur=adresse@exemple.ma');

            return self::FAILURE;
        }

        $brut = file_get_contents($chemin);
        $lignes = json_decode($brut, true);

        if (! is_array($lignes)) {
            $this->error('json_invalide='.$chemin);

            return self::FAILURE;
        }

        $retenues = array_values(array_filter($lignes, fn ($l) => ($l['famille'] ?? null) === $famille));

        if ($retenues === []) {
            $this->error('famille_sans_ligne='.$famille);
            $this->line('Familles présentes : '
                .collect($lignes)->pluck('famille')->unique()->filter()->sort()->implode(' · '));

            return self::FAILURE;
        }

        [$originaux, $doublons] = $this->separer($retenues);

        $this->line('fichier='.$chemin);
        $this->line('empreinte='.substr(hash('sha256', $brut), 0, 16).'…');
        $this->line('famille='.$famille);
        $this->line('epreuve='.$epreuve->code);
        $this->line('acteur='.$acteur->email);
        $this->line('environnement='.app()->environment());
        $this->newLine();
        $this->line('lignes_retenues='.count($retenues));
        $this->line('  originaux='.count($originaux));
        $this->line('  doublons='.count($doublons).' (préparés, jamais transférés)');
        $this->line('  pre_classees='.count(array_filter($retenues, fn ($l) => filled($l['domaine_code'] ?? null))));
        $this->line('  a_qualifier='.count(array_filter($retenues, fn ($l) => blank($l['domaine_code'] ?? null))));

        if ($this->option('simulation')) {
            $this->newLine();
            $this->line('mode=simulation');
            $this->line('Rien n’a été écrit. Retirez --simulation pour importer.');

            return self::SUCCESS;
        }

        $lot = $preparation->startBatch($acteur, $chemin, hash('sha256', $brut), [
            'famille' => $famille,
            'epreuve' => $epreuve->code,
            'lignes' => count($retenues),
        ]);

        /*
         * REJOUÉE SUR LE MÊME FICHIER, ELLE DIT CE QUI EXISTE ET S'ARRÊTE.
         *
         * Le lot est unique par l'empreinte du fichier : relancer la commande
         * retombe sur le même. Un lot terminé ne se reprend pas — c'est le
         * service qui le décide, et il a raison : ses lignes SONT déjà en
         * base. Forcer une reprise réécrirait un travail achevé pour arriver
         * au même endroit. Le précédent est `naja7i:creer-un-administrateur`.
         */
        if ($lot->status === QuestionPreparationBatchStatus::COMPLETED) {
            $deja = PreparedQuestion::where('batch_id', $lot->id)->where('active', true)->count();

            $this->newLine();
            $this->warn('lot_deja_termine='.$lot->uuid);
            $this->line('lignes_actives='.$deja);
            $this->line('Ce fichier a déjà été importé. Rien n’a été modifié.');

            return self::SUCCESS;
        }

        $poses = [];
        $echecs = [];

        /* DEUX PASSES, ET L'ORDRE COMPTE. Un doublon référence son original par
         * `import_ref` : l'original doit exister en base avant qu'on le vise.
         * Les 75 doublons du corpus ont tous leur original dans la même
         * famille — vérifié — donc la seconde passe ne cherche jamais dehors. */
        foreach ([$originaux, $doublons] as $passe) {
            foreach ($passe as $ligne) {
                $ref = (string) ($ligne['id'] ?? '');

                try {
                    $original = null;
                    if (filled($ligne['doublon_de'] ?? null)) {
                        $original = $poses[$ligne['doublon_de']]
                            ?? PreparedQuestion::where('import_ref', $ligne['doublon_de'])
                                ->where('active', true)->first();

                        if ($original === null) {
                            $echecs[$ref] = 'original_absent='.$ligne['doublon_de'];

                            continue;
                        }
                    }

                    $poses[$ref] = $preparation->prepare(
                        $lot,
                        $ref,
                        $this->faitsDeSource($ligne),
                        $this->provisoires($ligne),
                        [],
                        $original,
                        $epreuve,
                    );
                } catch (Throwable $e) {
                    $echecs[$ref] = $e->getMessage();
                }
            }
        }

        $preparation->completeBatch($lot->fresh(), $acteur);

        $enBase = PreparedQuestion::where('batch_id', $lot->id)->where('active', true);

        $this->newLine();
        $this->info('Import terminé.');
        $this->line('lot='.$lot->uuid);
        $this->line('preparees='.count($poses));
        $this->line('en_base_actives='.(clone $enBase)->count());
        $this->line('rangees_sous_l_epreuve='.(clone $enBase)->where('exam_id', $epreuve->id)->count());
        $this->line('avec_un_noeud='.(clone $enBase)->whereNotNull('competency_node_id')->count().' (doit être 0)');
        $this->line('echecs='.count($echecs));

        foreach ($echecs as $ref => $motif) {
            $this->warn('  '.$ref.' : '.$motif);
        }

        Log::info('Import du corpus QCM en zone de préparation', [
            'lot' => $lot->uuid,
            'famille' => $famille,
            'epreuve' => $epreuve->code,
            'acteur' => $acteur->email,
            'environnement' => app()->environment(),
            'preparees' => count($poses),
            'echecs' => count($echecs),
        ]);

        return $echecs === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function separer(array $lignes): array
    {
        $originaux = [];
        $doublons = [];

        foreach ($lignes as $l) {
            if (filled($l['doublon_de'] ?? null)) {
                $doublons[] = $l;
            } else {
                $originaux[] = $l;
            }
        }

        return [$originaux, $doublons];
    }

    /** @return array<string, mixed> */
    private function faitsDeSource(array $ligne): array
    {
        return array_intersect_key($ligne, array_flip(self::FAITS_DE_SOURCE));
    }

    /**
     * Le pré-classement voyage ICI, et nulle part ailleurs.
     *
     * `domaine_code`, `domaine_confiance` et `domaine_motif` sont conservés
     * tels quels — l'expert les verra à côté de la question et pourra les
     * suivre ou les contredire. Ce qu'ils ne font jamais, c'est renseigner
     * `competency_node_id`.
     *
     * @return array<string, mixed>
     */
    private function provisoires(array $ligne): array
    {
        $garde = array_intersect_key($ligne, array_flip(self::PROVISOIRES));

        /*
         * UNE CHAÎNE VIDE N'EST PAS UNE VALEUR, et la laisser entrer est un
         * mensonge tranquille. Les 32 questions SE non classées portent
         * `domaine_code: ""` dans le corpus, pas `null` : recopiées telles
         * quelles, elles RESSEMBLENT à un pré-classement. Un écran qui
         * filtrerait « domaine_code renseigné » en compterait 245 au lieu de
         * 213, et l'expert croirait qu'un script a déjà tranché.
         *
         * Mesuré sur la préproduction le 24 août, après l'import : les 245
         * lignes portaient un `domaine_code` de type `string`. Le compte
         * annoncé par la commande, lui, était juste — `filled()` voit bien la
         * chaîne vide. C'est le STOCKAGE qui mentait, pas le rapport.
         */
        return array_filter($garde, fn ($v) => $v !== '' && $v !== null);
    }

    /** Même garde que `naja7i:creer-un-administrateur` : cette commande écrit. */
    private function assertEnvironnementNomme(): bool
    {
        if (filled($this->option('env'))) {
            return true;
        }

        $this->error('env_absent=1');
        $this->line(
            'Nommez l’environnement : --env=local, --env=staging, --env=production. '
            .'Cette commande écrit en base ; elle ne devine pas où.'
        );

        return false;
    }
}
