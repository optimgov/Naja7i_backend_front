<?php

namespace App\Services;

use App\Models\CompetencyNode;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Source;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * L'import des annales dans la FILE ÉDITORIALE — lot CRMEF-2, phase 3.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA RÈGLE QUI COMMANDE TOUT LE RESTE
 *
 * Ces questions entrent en BROUILLON, jamais dans la banque publiable. Le
 * corpus n'a AUCUN corrigé officiel : une question sans bonne réponse établie
 * ne peut pas être servie à un candidat, et ne doit pas POUVOIR l'être.
 *
 * Ce n'est pas tenu par une consigne mais par construction : `status = draft`,
 * `authoring = imported`, `eligible_for_diagnostic` et `eligible_for_simulation`
 * à faux, et AUCUNE option marquée correcte. La garde de publication — service
 * et déclencheur PostgreSQL — refuse alors deux fois : « exactement une bonne
 * réponse est attendue (0 trouvée) » et « distracteur sans cause d'erreur ».
 *
 * Un test le prouve dans les deux sens, et c'est le cœur du lot.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LES MARQUES MANUSCRITES NE SONT PAS DES RÉPONSES
 *
 * Elles vont dans `import_note`, en toutes lettres, avec leur qualification du
 * corpus. Jamais dans `is_correct`. Le corpus établit qu'elles sont anonymes et
 * « contradictoires ou multiples sur des dizaines de questions » : Q85 de 2024
 * porte D et E, Q110 de 2025 en porte trois. Une source qui se contredit n'est
 * pas une source — elle oriente la recherche d'un relecteur, elle ne conclut
 * rien.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA CANONISATION DES NUMÉROS EST LE POINT DE DÉFAILLANCE SILENCIEUSE
 *
 * Le corpus titre tantôt `##### Q1`, tantôt `##### Q 1`, tantôt `##### Q01`. La
 * table de classement a canonisé en `Q<entier sans zéro initial>`. Si l'import
 * canonise autrement, la jointure ne lève AUCUNE erreur : elle ne trouve
 * simplement rien, sur deux blocs entiers. La note de vérification du classement
 * l'avait signalé comme le mode de défaillance à surveiller ; `canoniser()` est
 * donc partagée, et un test la couvre sur les trois formes.
 */
final class ImportAnnales
{
    /** @var list<array<string, mixed>> */
    private array $rejets = [];

    private int $lues = 0;

    private int $importees = 0;

    private int $inchangees = 0;

    /**
     * @param  array<string, array{code_noeud: string, confiance: string, motif: string}>  $classement
     *                                                                                                  indexé par « sujet|numéro canonisé »
     * @return array<string, mixed> le rapport chiffré
     */
    public function importer(string $corpus, array $classement, string $bloc, bool $simulation = false): array
    {
        $section = $this->section($corpus, $bloc);

        if ($section === null) {
            return $this->rapport($bloc, "Bloc « {$bloc} » introuvable dans le corpus.");
        }

        $entete = $this->entete($section);
        $questions = $this->questions($section);
        $this->lues = count($questions);

        DB::transaction(function () use ($bloc, $entete, $questions, $classement, $simulation) {
            $source = $this->source($bloc, $entete);

            foreach ($questions as $q) {
                $this->une($bloc, $q, $classement, $source, $simulation);
            }
        });

        return $this->rapport($bloc);
    }

    /**
     * `Q1`, `Q 1` et `Q01` désignent la même question. Cette fonction est la
     * seule autorité sur ce point, des deux côtés de la jointure.
     */
    public static function canoniser(string $brut): ?string
    {
        if (preg_match('/^Q\s*0*(\d+)$/u', trim($brut), $t) !== 1) {
            return null;
        }

        return 'Q'.$t[1];
    }

    /** La section du corpus qui porte ce bloc, du titre au titre suivant. */
    private function section(string $corpus, string $bloc): ?string
    {
        $marque = "*Source : `{$bloc}`";
        $debut = mb_strpos($corpus, $marque);

        if ($debut === false) {
            return null;
        }

        $suite = mb_strpos($corpus, "\n## ", $debut);

        return mb_substr($corpus, $debut, $suite === false ? null : $suite - $debut);
    }

    /** @return array<string, string> */
    private function entete(string $section): array
    {
        $champs = [];

        foreach ([
            'intitule' => "Intitulé exact de l'épreuve",
            'session' => 'Session (دورة) telle qu\'imprimée',
            'autorite' => 'Autorité émettrice imprimée sur le document',
            'filigrane' => 'Filigrane / mention de site web',
            'corrige' => 'Un corrigé est-il fourni',
        ] as $cle => $prefixe) {
            /* `[^:\n]*` ET NON `[^:]*` : une classe négative traverse les
             * retours à la ligne. « Un corrigé est-il fourni dans le document ? »
             * ne porte pas de deux-points, et la première écriture happait donc
             * le paragraphe suivant jusqu'au premier « : » rencontré. */
            if (preg_match('/^- '.preg_quote($prefixe, '/').'[^:\n]*[:?]\s*(.+)$/um', $section, $t) === 1) {
                $champs[$cle] = trim($t[1]);
            }
        }

        return $champs;
    }

    /** @return list<array<string, mixed>> */
    private function questions(string $section): array
    {
        $morceaux = preg_split('/^##### /um', $section);
        $questions = [];

        foreach (array_slice($morceaux ?: [], 1) as $morceau) {
            $lignes = explode("\n", $morceau);
            $numero = self::canoniser(array_shift($lignes) ?? '');

            if ($numero === null) {
                continue;
            }

            $corps = implode("\n", $lignes);

            $options = [];
            if (preg_match_all('/^- ([A-E])\.\s*(.+)$/um', $corps, $t, PREG_SET_ORDER) > 0) {
                foreach ($t as $o) {
                    $options[] = ['lettre' => $o[1], 'contenu' => trim($o[2])];
                }
            }

            $questions[] = [
                'numero' => $numero,
                'enonce' => $this->champ($corps, 'Énoncé'),
                'options' => $options,
                'marque' => $this->champ($corps, 'Réponse indiquée dans le document'),
                'page' => $this->champ($corps, 'Page'),
                'fiabilite' => $this->champ($corps, 'Fiabilité'),
            ];
        }

        return $questions;
    }

    private function champ(string $corps, string $nom): ?string
    {
        if (preg_match('/^\*\*'.preg_quote($nom, '/').'\s*:\*\*\s*(.*)$/um', $corps, $t) !== 1) {
            return null;
        }

        return trim($t[1]) === '' ? null : trim($t[1]);
    }

    /**
     * La source du sujet, avec son STATUT RÉEL — partie 5 du corpus.
     *
     * `kind = annale` et non `descriptif_officiel` : c'est une reproduction de
     * tiers, pas un descriptif. Elle n'est PAS vérifiée, et ne peut pas l'être
     * en l'état : la partie 5 est catégorique, « aucun document de ce corpus
     * n'est officiel au sens strict ».
     */
    private function source(string $bloc, array $entete): Source
    {
        return Source::firstOrCreate(
            ['code' => 'SRC-ANNALE-'.Str::upper(str_replace('_', '-', $bloc))],
            [
                'kind' => 'annale',
                'title_fr' => 'Annale — '.$bloc,
                /* Ces trois colonnes sont des `varchar(255)`. L'autorité
                 * imprimée sur ce sujet fait 300 caractères — elle reproduit
                 * aussi la graphie tifinagh du bandeau. On la coupe ICI plutôt
                 * que de laisser PostgreSQL refuser l'import entier, et la
                 * version intégrale reste dans `location_note_fr`, qui est un
                 * texte. Rien n'est perdu, et rien n'est deviné. */
                'title_ar' => $this->borner($entete['intitule'] ?? null),
                'authority_fr' => $this->borner($entete['autorite'] ?? null),
                'session_label' => $this->borner($entete['session'] ?? null),
                'location_note_fr' => trim(implode(' · ', array_filter([
                    'Reproduction diffusée par un tiers, non officielle.',
                    isset($entete['autorite']) ? 'Autorité imprimée, en entier : '.$entete['autorite'] : null,
                    isset($entete['filigrane']) ? 'Filigrane : '.$entete['filigrane'] : null,
                    isset($entete['corrige']) ? 'Corrigé fourni : '.$entete['corrige'] : null,
                ]))),
            ]
        );
    }

    /** Coupe à la limite de la colonne, sans couper un caractère en deux. */
    private function borner(?string $valeur, int $max = 255): ?string
    {
        if ($valeur === null || mb_strlen($valeur) <= $max) {
            return $valeur;
        }

        return mb_substr($valeur, 0, $max - 1).'…';
    }

    private function une(string $bloc, array $q, array $classement, Source $source, bool $simulation): void
    {
        $cle = $bloc.'|'.$q['numero'];
        $ligne = $classement[$cle] ?? null;

        if ($ligne === null || trim($ligne['code_noeud']) === '') {
            $this->rejeter($q['numero'], 'aucun domaine de rattachement dans la table de classement',
                $ligne['motif'] ?? null);

            return;
        }

        $noeud = CompetencyNode::where('code', trim($ligne['code_noeud']))->first();

        if ($noeud === null) {
            $this->rejeter($q['numero'], "code de nœud inconnu : {$ligne['code_noeud']}");

            return;
        }

        if ($q['enonce'] === null || $q['options'] === []) {
            $this->rejeter($q['numero'], 'énoncé ou options illisibles dans le corpus');

            return;
        }

        /* L'ÉPREUVE VIENT DU NŒUD, jamais d'une supposition : `competency_nodes`
         * pend d'une épreuve (ADR-0014), et c'est ce lien qui décide de la voie.
         * Un nœud d'une autre voie ferait donc échouer ici plutôt que de
         * rattacher une annale à une épreuve qui n'est pas la sienne. */
        $epreuve = $noeud->exam;

        if ($epreuve === null) {
            $this->rejeter($q['numero'], "le nœud {$noeud->code} ne pend d'aucune épreuve");

            return;
        }

        if ($simulation) {
            $this->importees++;

            return;
        }

        $deja = Question::where('import_ref', $cle)->first();

        if ($deja !== null) {
            $this->inchangees++;

            return;
        }

        $question = Question::create([
            'exam_id' => $epreuve->id,
            'competency_node_id' => $noeud->id,
            'locale' => 'ar',
            'sibling_group' => (string) Str::uuid7(),
            'stem' => $q['enonce'],
            'status' => 'draft',
            'authoring' => 'imported',
            'eligible_for_diagnostic' => false,
            'eligible_for_simulation' => false,
            'import_ref' => $cle,
            'import_note' => $this->note($q, $ligne),
        ]);

        foreach ($q['options'] as $rang => $option) {
            /*
             * AUCUNE OPTION N'EST MARQUÉE CORRECTE. Le corpus n'a pas de
             * corrigé ; marquer quoi que ce soit ici serait fabriquer une
             * vérité. `rationale` reste vide : F04 l'exige à la publication,
             * et c'est précisément ce qui bloquera cette question tant qu'un
             * relecteur ne l'aura pas écrite.
             */
            QuestionOption::create([
                'question_id' => $question->id,
                'position' => $rang + 1,
                'content' => $option['lettre'].'. '.$option['contenu'],
                'is_correct' => false,
            ]);
        }

        $question->contentSources()->attach($source->id, [
            'locator' => $q['page'] !== null ? 'page '.$q['page'] : null,
            /* `unverified` : la valeur de l'énumération du dépôt. Une annale de
             * ce corpus ne PEUT PAS être vérifiée — la partie 5 est catégorique,
             * « aucun document de ce corpus n'est officiel au sens strict ». Et
             * c'est ce drapeau que lit la garde de publication : sans source
             * vérifiée, aucune éligibilité au diagnostic ni à la simulation. */
            'verification' => 'unverified',
        ]);

        $this->importees++;
    }

    /** La trace de lecture, telle qu'un relecteur doit la voir. */
    private function note(array $q, array $ligne): string
    {
        $marque = $q['marque'] ?? 'non renseignée';
        $multiple = preg_match('/\bet\b|marques manuscrites|plusieurs/u', $marque) === 1;

        return trim(implode("\n", array_filter([
            'MARQUE MANUSCRITE RELEVÉE SUR LE SCAN : '.$marque,
            $multiple
                ? 'ATTENTION — plusieurs marques, ou marques contradictoires. Le corpus (§4.1) '
                    .'établit qu’elles sont anonymes et non officielles. Ce n’est pas une réponse.'
                : 'Ce n’est pas une réponse : marque anonyme, non officielle (corpus §4.1).',
            'Fiabilité de lecture : '.($q['fiabilite'] ?? 'non renseignée'),
            $q['page'] !== null ? 'Page du scan : '.$q['page'] : null,
            'Classement du domaine — confiance déclarée : '.($ligne['confiance'] ?: 'non renseignée'),
            trim((string) ($ligne['motif'] ?? '')) !== '' ? 'Motif du classement : '.$ligne['motif'] : null,
            str_contains((string) $q['enonce'], '[illisible]')
                ? 'L’énoncé contient au moins un passage marqué [illisible] : à relire sur le scan.'
                : null,
        ])));
    }

    private function rejeter(string $numero, string $motif, ?string $detail = null): void
    {
        $this->rejets[] = ['numero' => $numero, 'motif' => $motif, 'detail' => $detail];
    }

    /** @return array<string, mixed> */
    private function rapport(string $bloc, ?string $erreur = null): array
    {
        return [
            'bloc' => $bloc,
            'erreur' => $erreur,
            'lues' => $this->lues,
            'importees' => $this->importees,
            'inchangees' => $this->inchangees,
            'rejetees' => count($this->rejets),
            'rejets' => $this->rejets,
        ];
    }
}
