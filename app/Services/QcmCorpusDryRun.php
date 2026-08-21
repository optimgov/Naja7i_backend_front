<?php

namespace App\Services;

use App\Models\CompetencyNode;
use App\Models\Question;
use Illuminate\Support\Str;
use JsonException;

/**
 * Valide et projette le corpus JSON de pré-saisie sans aucune écriture.
 *
 * Les marques manuscrites restent dans `import_metadata`. Elles ne sont jamais
 * traduites en `is_correct`, qui vaut faux pour chaque option projetée.
 */
final class QcmCorpusDryRun
{
    /** @return array<string, mixed> */
    public function analyser(string $path): array
    {
        $bytes = file_get_contents($path);

        if ($bytes === false) {
            return $this->fatal($path, 'fichier illisible');
        }

        if (! mb_check_encoding($bytes, 'UTF-8')) {
            return $this->fatal($path, 'encodage non UTF-8');
        }

        try {
            $rows = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $this->fatal($path, 'JSON invalide : '.$e->getMessage());
        }

        if (! is_array($rows) || ! array_is_list($rows)) {
            return $this->fatal($path, 'la racine JSON doit être une liste');
        }

        $domainCodes = collect($rows)
            ->pluck('domaine_code')->filter(fn ($code) => is_string($code) && trim($code) !== '')
            ->map(fn ($code) => trim($code))->unique()->values();

        $nodes = CompetencyNode::query()
            ->with('exam:id,code')
            ->whereIn('code', $domainCodes)
            ->get()->keyBy('code');

        $ids = [];
        $subjects = [];
        $projections = [];
        $rejections = [];
        $anomalies = [];
        $duplicates = [];
        $suggestions = 0;
        $ambiguous = 0;
        $unreadable = 0;
        $multilineReliabilities = 0;
        $strictContexts = 0;
        $contextReasons = [
            'separateur_markdown' => 0,
            'titre_markdown_niveau_2' => 0,
            'libelle_texte_support' => 0,
        ];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $rejections[] = $this->issue($index, null, 'ligne_non_objet');

                continue;
            }

            $id = is_string($row['id'] ?? null) ? trim($row['id']) : '';
            $subject = is_string($row['sujet'] ?? null) ? trim($row['sujet']) : '';
            $status = $row['statut'] ?? null;
            $options = $row['options'] ?? null;
            $declaredOptions = $row['nb_options'] ?? null;

            if ($id === '' || $subject === '' || ! is_array($options)) {
                $rejections[] = $this->issue($index, $id, 'structure_obligatoire_incomplete');

                continue;
            }

            if (isset($ids[$id])) {
                $rejections[] = $this->issue($index, $id, 'import_ref_duplique');

                continue;
            }

            $ids[$id] = true;
            $subjects[$subject] = true;
            $isUnreadable = $status === 'source_illisible';
            $unreadable += (int) $isUnreadable;
            $suggestions += (int) (is_string($row['suggestion_reponse'] ?? null) && trim($row['suggestion_reponse']) !== '');
            $ambiguous += (int) (($row['suggestion_ambigue'] ?? false) === true);

            if (($row['reponse_correcte'] ?? null) !== null) {
                $rejections[] = $this->issue($index, $id, 'reponse_correcte_non_vide');

                continue;
            }

            /* Les sujets à quatre choix conservent parfois une clé E vide dans
             * la projection brute. Le nombre IMPRIMÉ porte sur les choix non
             * vides : on ne transforme donc pas cette sentinelle en option. */
            $printedOptions = collect($options)
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->all();
            $letters = array_keys($printedOptions);
            $expected = array_slice(['A', 'B', 'C', 'D', 'E'], 0, count($printedOptions));
            $validOptions = in_array(count($printedOptions), [4, 5], true)
                && $letters === $expected
                && $declaredOptions === count($printedOptions);

            if (! $validOptions && ! $isUnreadable) {
                $rejections[] = $this->issue($index, $id, 'options_invalides', [
                    'declarees' => $declaredOptions,
                    'observees' => count($printedOptions),
                    'lettres' => $letters,
                ]);

                continue;
            }

            if (! $validOptions) {
                $anomalies[] = $this->issue($index, $id, 'source_illisible_non_importable');
            }

            $duplicateOf = $row['doublon_de'] ?? null;
            if (is_string($duplicateOf) && trim($duplicateOf) !== '') {
                $duplicates[$id] = trim($duplicateOf);
            }

            $reliability = is_string($row['fiabilite_lecture'] ?? null)
                ? $row['fiabilite_lecture']
                : '';
            $isMultiline = preg_match('/\R/u', $reliability) === 1;
            $reasons = array_filter([
                'separateur_markdown' => preg_match('/(?:^|\R)\s*---\s*(?:$|\R)/u', $reliability) === 1,
                /* `##` est un bloc documentaire. `### 14`, observé trois fois,
                 * est une note de numérotation : multiligne, mais pas un texte
                 * support infiltré et donc pas bloquant. */
                'titre_markdown_niveau_2' => preg_match('/(?:^|\R)\s*##\s+[^#]/u', $reliability) === 1,
                'libelle_texte_support' => preg_match('/Texte support\b/u', $reliability) === 1,
            ]);
            $hasMarkdownContext = $reasons !== [];

            $multilineReliabilities += (int) $isMultiline;
            if ($hasMarkdownContext) {
                $strictContexts++;
                foreach (array_keys($reasons) as $reason) {
                    $contextReasons[$reason]++;
                }
                $anomalies[] = $this->issue($index, $id, 'contexte_markdown_dans_fiabilite', [
                    'motifs' => array_keys($reasons),
                    'texte_integral' => $reliability,
                ]);
            }

            $fieldIssues = $this->validateProvisionalFields($row, $printedOptions);
            foreach ($fieldIssues as $fieldIssue) {
                $anomalies[] = $this->issue($index, $id, $fieldIssue);
            }

            $domainCode = is_string($row['domaine_code'] ?? null) ? trim($row['domaine_code']) : '';
            $node = $domainCode !== '' ? $nodes->get($domainCode) : null;

            if ($domainCode === '') {
                $anomalies[] = $this->issue($index, $id, 'domaine_a_produire');
            } elseif ($node === null) {
                $anomalies[] = $this->issue($index, $id, 'domaine_inconnu', ['code' => $domainCode]);
            } elseif ($node->exam === null) {
                $anomalies[] = $this->issue($index, $id, 'domaine_sans_epreuve', ['code' => $domainCode]);
            }

            $projections[] = [
                'question' => [
                    'import_ref' => $id,
                    'exam_code' => $node?->exam?->code,
                    'competency_node_code' => $node?->code,
                    'locale' => $this->locale((string) ($row['enonce'] ?? '')),
                    'stem' => $row['enonce'] ?? null,
                    'difficulty' => $row['difficulte'] ?? null,
                    'status' => 'draft',
                    'authoring' => 'imported',
                    'eligible_for_diagnostic' => false,
                    'eligible_for_simulation' => false,
                    'import_metadata' => $this->metadata($row, $reliability, $hasMarkdownContext),
                ],
                'options' => collect($printedOptions)->map(
                    fn ($content, $letter) => [
                        'position' => ord((string) $letter) - ord('A') + 1,
                        /* Verbatim : la lettre est déjà portée par position. */
                        'content' => $content,
                        'is_correct' => false,
                        'rationale' => null,
                        'cause' => null,
                    ]
                )->values()->all(),
                'source' => [
                    'code' => 'SRC-ANNALE-'.Str::upper(str_replace('_', '-', $subject)),
                    'kind' => 'annale',
                    'locator' => trim((string) ($row['page_source'] ?? '')) ?: null,
                    'verification' => 'unverified',
                ],
                'importable' => $validOptions
                    && $node?->exam !== null
                    && ! $hasMarkdownContext
                    && $fieldIssues === [],
            ];
        }

        foreach ($duplicates as $id => $target) {
            if (! isset($ids[$target])) {
                $rejections[] = $this->issue(null, $id, 'doublon_cible_absente', ['cible' => $target]);
            }
        }

        $existingRefs = Question::query()
            ->whereIn('import_ref', array_keys($ids))
            ->pluck('import_ref')
            ->filter()
            ->flip();

        $projections = array_map(function (array $projection) use ($existingRefs): array {
            $projection['deja_present'] = $existingRefs->has($projection['question']['import_ref']);

            return $projection;
        }, $projections);

        $importable = count(array_filter($projections, fn ($row) => $row['importable']));
        $alreadyPresent = count(array_filter($projections, fn ($row) => $row['deja_present']));

        return [
            'path' => realpath($path) ?: $path,
            'sha256' => hash('sha256', $bytes),
            'valid_json' => true,
            'utf8' => true,
            'lignes' => count($rows),
            'sujets' => count($subjects),
            'suggestions' => $suggestions,
            'suggestions_ambigues' => $ambiguous,
            'doublons' => count($duplicates),
            'sources_illisibles' => $unreadable,
            'fiabilites_multilignes' => $multilineReliabilities,
            'contextes_markdown' => $strictContexts,
            'multilignes_non_bloquants' => $multilineReliabilities - $strictContexts,
            'motifs_contextes' => $contextReasons,
            'projections' => count($projections),
            'importables' => $importable,
            'deja_presents' => $alreadyPresent,
            'a_importer' => count(array_filter(
                $projections,
                fn ($row) => $row['importable'] && ! $row['deja_present']
            )),
            'bloquees' => count($projections) - $importable,
            'statistiques_rejets' => collect($rejections)->countBy('code')->sortKeys()->all(),
            'statistiques_anomalies' => collect($anomalies)->countBy('code')->sortKeys()->all(),
            'rejets' => $rejections,
            'anomalies' => $anomalies,
            'mapping' => $projections,
            'import_ready' => count($rejections) === 0 && count($projections) - $importable === 0,
        ];
    }

    /** @return array<string, mixed> */
    private function metadata(array $row, string $reliability, bool $hasMarkdownContext): array
    {
        return [
            'schema' => 'naja7i-qcm-prevalidation-v1',
            'source_facts' => collect($row)->only([
                'sujet', 'numero', 'numero_int', 'voie', 'session', 'famille', 'discipline',
                'arbre_cible', 'domaine_imprime', 'page_source', 'fiabilite_lecture',
                'doublon_de', 'suggestion_reponse', 'suggestion_origine', 'suggestion_ambigue',
                'enonce', 'options', 'nb_options',
            ])->all(),
            'provisional' => collect($row)->only([
                'domaine_code', 'domaine_confiance', 'domaine_motif', 'difficulte',
                'temps_s', 'valeurs_par_defaut', 'priorite', 'statut',
            ])->all(),
            'human_fields' => collect($row)->only([
                'reponse_correcte', 'justifications', 'piege', 'source', 'niveau_cognitif',
                'microcompetence', 'doute', 'auteur', 'valideur', 'horodatage_saisie',
                'horodatage_validation',
            ])->all(),
            'parser_trace' => $hasMarkdownContext ? [
                'kind' => 'context_in_reading_reliability',
                'preserved_text' => $reliability,
            ] : null,
        ];
    }

    private function locale(string $text): string
    {
        return preg_match('/\p{Arabic}/u', $text) === 1 ? 'ar' : 'fr';
    }

    /** @return list<string> */
    private function validateProvisionalFields(array $row, array $printedOptions): array
    {
        $issues = [];
        $suggestion = $row['suggestion_reponse'] ?? null;

        if ($suggestion !== null && (! is_string($suggestion)
            || preg_match('/^[A-E]$/', $suggestion) !== 1
            || ! array_key_exists($suggestion, $printedOptions))) {
            $issues[] = 'suggestion_reponse_invalide';
        }

        $difficulty = $row['difficulte'] ?? null;
        if (! is_int($difficulty) || $difficulty < 1 || $difficulty > 4) {
            $issues[] = 'difficulte_invalide';
        }

        $time = $row['temps_s'] ?? null;
        if (! is_int($time) || $time < 45 || $time > 130) {
            $issues[] = 'temps_s_invalide';
        }

        $defaults = $row['valeurs_par_defaut'] ?? null;
        if (! is_array($defaults)
            || ! array_is_list($defaults)
            || count($defaults) !== count(array_unique($defaults))
            || array_diff($defaults, ['difficulte', 'temps_s']) !== []) {
            $issues[] = 'valeurs_par_defaut_invalides';
        }

        if (! in_array($row['statut'] ?? null, ['a_saisir', 'saisi', 'valide', 'source_illisible'], true)) {
            $issues[] = 'statut_inconnu';
        }

        return $issues;
    }

    /** @return array<string, mixed> */
    private function issue(?int $index, ?string $id, string $code, array $details = []): array
    {
        return array_filter([
            'index' => $index,
            'id' => $id,
            'code' => $code,
            'details' => $details ?: null,
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    private function fatal(string $path, string $message): array
    {
        return [
            'path' => $path,
            'valid_json' => false,
            'utf8' => ! str_contains($message, 'UTF-8'),
            'fatal' => $message,
            'lignes' => 0,
            'rejets' => [],
            'anomalies' => [],
            'mapping' => [],
        ];
    }
}
