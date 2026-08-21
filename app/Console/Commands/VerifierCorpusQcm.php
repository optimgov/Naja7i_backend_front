<?php

namespace App\Console\Commands;

use App\Services\QcmCorpusDryRun;
use Illuminate\Console\Command;

final class VerifierCorpusQcm extends Command
{
    protected $signature = 'qcm:verifier
        {fichier : Chemin du corpus JSON}
        {--rapport= : Écrire le rapport JSON à cet emplacement}
        {--strict : Échouer si le corpus comporte un rejet ou une question bloquée}';

    protected $description = 'Valide et projette un corpus QCM sans aucune écriture en base';

    public function handle(QcmCorpusDryRun $dryRun): int
    {
        $report = $dryRun->analyser((string) $this->argument('fichier'));
        $summary = collect($report)->except(['mapping', 'rejets', 'anomalies'])->all();

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->components->info(sprintf(
            '%d lignes, %d sujets, %d importables, %d bloquées, %d rejets, %d anomalies.',
            $report['lignes'] ?? 0,
            $report['sujets'] ?? 0,
            $report['importables'] ?? 0,
            $report['bloquees'] ?? 0,
            count($report['rejets'] ?? []),
            count($report['anomalies'] ?? []),
        ));

        if (is_string($this->option('rapport')) && $this->option('rapport') !== '') {
            $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            file_put_contents((string) $this->option('rapport'), $encoded."\n");
        }

        if (! ($report['valid_json'] ?? false)) {
            return self::FAILURE;
        }

        return $this->option('strict') && ! ($report['import_ready'] ?? false)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
