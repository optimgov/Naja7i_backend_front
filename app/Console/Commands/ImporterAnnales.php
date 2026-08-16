<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\ImportAnnales;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * `php artisan crmef:importer-annales`
 *
 * Importe un bloc du corpus dans la FILE ÉDITORIALE. Rejouable : l'empreinte
 * `(sujet, numéro canonisé)` porte un index unique, et un second passage ne
 * crée aucun doublon.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * UN SEUL BLOC PAR DÉFAUT, ET C'EST UNE DÉCISION
 *
 * `2025_SCED_college_qualifiant` — 53 questions. C'est le SEUL bloc classé dont
 * les nœuds appartiennent à l'épreuve qu'il vise.
 *
 * Mesuré plutôt que supposé : les neuf nœuds `SE-*` pendent tous de
 * `CRMEF-SE-2025`, qui relève de la voie B « Secondaire collégial et
 * qualifiant ». Or 160 des 213 questions classées viennent des voies A
 * (primaire) et C (secondaire 2e grade), que le dépôt NE MODÉLISE PAS — décision
 * du pilote, DET-61 : créer leurs arbres sans descriptif répéterait trois fois
 * la faute que DET-60 dénonce, cette fois en connaissance de cause.
 *
 * Les importer sous `CRMEF-SE-2025` les rattacherait à une épreuve qui n'est pas
 * la leur : autre intitulé, autre autorité émettrice, autre barème, autre nombre
 * d'options. Un import de 53 questions vraies vaut mieux qu'un import de 213
 * dont 160 sont fausses de rattachement.
 *
 * Elles sont donc COMPTÉES au rapport, par voie et par bloc, et laissées
 * dehors.
 */
class ImporterAnnales extends Command
{
    protected $signature = 'crmef:importer-annales
        {--bloc=2025_SCED_college_qualifiant : le bloc du corpus à importer}
        {--tous : importer tous les blocs classés — refusé tant que DET-61 n\'est pas levée}
        {--simulation : ne rien écrire, seulement compter}';

    protected $description = 'Importe les annales d’un bloc du corpus dans la file éditoriale (brouillons)';

    private const CORPUS = 'docs/corpus/CRMEF-extraction-20260815.md';

    private const CLASSEMENT = 'docs/corpus/CRMEF-classement-domaines-20260815.csv';

    public function handle(ImportAnnales $import): int
    {
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $corpus = base_path(self::CORPUS);
        $classementFichier = base_path(self::CLASSEMENT);

        foreach ([$corpus, $classementFichier] as $f) {
            if (! is_readable($f)) {
                $this->error("Introuvable ou illisible : {$f}");

                return self::FAILURE;
            }
        }

        $classement = $this->classement($classementFichier);
        $this->couverture($classement);

        if ($this->option('tous')) {
            $this->newLine();
            $this->error('Refusé : `--tous` importerait les voies A et C sous une épreuve de la voie B.');
            $this->line('  Voir DET-61. Le rattachement se décide sur l’épreuve du nœud, pas sur le volume.');

            return self::FAILURE;
        }

        $bloc = (string) $this->option('bloc');
        $simulation = (bool) $this->option('simulation');

        $this->newLine();
        $this->info($simulation ? "Simulation — bloc {$bloc}" : "Import — bloc {$bloc}");

        $rapport = $import->importer(file_get_contents($corpus), $classement, $bloc, $simulation);

        return $this->rendre($rapport);
    }

    /** @return array<string, array{code_noeud: string, confiance: string, motif: string}> */
    private function classement(string $fichier): array
    {
        $flux = fopen($fichier, 'r');
        $entetes = fgetcsv($flux);
        $lignes = [];

        while (($l = fgetcsv($flux)) !== false) {
            $ligne = array_combine($entetes, $l);

            $numero = ImportAnnales::canoniser((string) $ligne['numero_question']);

            if ($numero === null) {
                continue;
            }

            $lignes[$ligne['sujet'].'|'.$numero] = [
                'code_noeud' => (string) $ligne['code_noeud'],
                'confiance' => (string) $ligne['confiance'],
                'motif' => (string) $ligne['motif'],
            ];
        }

        fclose($flux);

        return $lignes;
    }

    /**
     * CE QUI EST EXPLOITABLE, ET CE QUI NE L'EST PAS — par bloc.
     *
     * Le rapport le plus utile du lot : il dit par où commencer la relecture, et
     * ce que le dépôt ne sait pas encore accueillir.
     *
     * @param  array<string, array<string, string>>  $classement
     */
    private function couverture(array $classement): void
    {
        $voies = [
            '2025_SCED_college_qualifiant' => 'B — collégial et qualifiant (modélisée)',
            '2025_SCED_primaire' => 'A — primaire (NON modélisée, DET-61)',
            '2024_SCED_primaire' => 'A — primaire (NON modélisée, DET-61)',
            '2025_DIDA_SCED_p31-44' => 'A — primaire (NON modélisée, DET-61)',
            '2023_SCED_frar_p01-12' => 'C — secondaire 2e grade (NON modélisée, DET-61)',
            '2023_SCED_frar_p13-24' => 'C — secondaire 2e grade (NON modélisée, DET-61)',
        ];

        $parBloc = [];

        foreach ($classement as $cle => $ligne) {
            if (trim($ligne['code_noeud']) === '') {
                continue;
            }

            $parBloc[explode('|', $cle)[0]] = ($parBloc[explode('|', $cle)[0]] ?? 0) + 1;
        }

        arsort($parBloc);

        $this->info('Questions classées, par bloc et par voie');
        $this->table(
            ['Bloc', 'Classées', 'Voie'],
            array_map(
                fn ($bloc, $n) => [$bloc, $n, $voies[$bloc] ?? 'voie non identifiée'],
                array_keys($parBloc),
                $parBloc
            )
        );

        $importables = $parBloc['2025_SCED_college_qualifiant'] ?? 0;
        $total = array_sum($parBloc);

        $this->line("  Total classé : {$total} · rattachables aujourd'hui : {$importables} · "
            .'hors périmètre : '.($total - $importables).' (DET-61)');
    }

    /** @param  array<string, mixed>  $r */
    private function rendre(array $r): int
    {
        if ($r['erreur'] !== null) {
            $this->error($r['erreur']);

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['', ''], [
            ['questions lues dans le corpus', $r['lues']],
            ['importées', $r['importees']],
            ['déjà présentes (rejeu)', $r['inchangees']],
            ['rejetées', $r['rejetees']],
        ]);

        /*
         * UNE ANOMALIE EST UN ÉCHEC BRUYANT, JAMAIS UN SILENCE. Chaque rejet est
         * listé avec son motif : une question tombée sans qu'on sache pourquoi
         * disparaîtrait du produit sans que personne la cherche.
         */
        if ($r['rejets'] !== []) {
            $this->newLine();
            $this->warn('Rejets — chacun avec son motif :');

            foreach ($r['rejets'] as $rejet) {
                $this->line("  {$rejet['numero']} — {$rejet['motif']}");

                if ($rejet['detail'] !== null && $rejet['detail'] !== '') {
                    $this->line('      '.mb_substr($rejet['detail'], 0, 160));
                }
            }
        }

        $this->newLine();
        $this->info('Ces questions sont des BROUILLONS : aucune bonne réponse, aucune justification,');
        $this->info('aucune éligibilité. Elles attendent un relecteur — c’est une file, pas une banque.');

        return self::SUCCESS;
    }
}
