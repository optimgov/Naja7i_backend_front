<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\CompetencyNode;
use Illuminate\Support\Collection;

/**
 * Le rapport d'un examen blanc : la note blanche, section par section.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POURQUOI UN SCORE A LE DROIT DE SORTIR ICI, ET NULLE PART AILLEURS
 *
 * DET-31 dit qu'un `correct_count` ne distingue pas le `kind` : un 9/10
 * d'entraînement se sérialise comme un 9/10 de diagnostic, alors qu'une série
 * d'entraînement est ciblée sur un point faible et n'est pas représentative —
 * par construction. Afficher « 90 % » à un candidat qui vient de réviser sa
 * lacune lui ferait croire qu'il est prêt.
 *
 * L'examen blanc est l'exception, et pour une raison STRUCTURELLE, pas
 * éditoriale : `DiagnosticComposer` répartit ses questions selon les
 * `weight_percent` officiels des sous-domaines (ADR-0014). La série reproduit
 * donc les poids de l'épreuve PAR CONSTRUCTION — un domaine qui pèse 15 % du
 * concours pèse 15 % de la série. Le score qui en sort mesure ce que le
 * concours mesurerait, à banque et poids égaux.
 *
 * C'est la seule surface du produit où un score se présente comme une note
 * d'épreuve. Il s'y présente comme NOTE BLANCHE, jamais comme prédiction.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * CE QUE CE RAPPORT NE DIRA JAMAIS, ET CE N'EST PAS UNE PRUDENCE DE FORME
 *
 * AUCUNE PRÉDICTION DE RÉUSSITE (METHODE §7.3), même dérivée : pas de
 * « vous seriez admis », pas de rang, pas de comparaison à un seuil.
 * `blueprints.official_admission_threshold_note_fr` existe et sera SERVI TEL
 * QUEL — c'est une citation du descriptif, pas un verdict appliqué au
 * candidat.
 *
 * PAS DE NOTE SUR 20. Le barème n'est pas public : les trois épreuves portent
 * `official_scoring_note_fr` = « Barème détaillé non précisé par le
 * descriptif », et `official_question_count` est NUL partout. La migration des
 * blueprints le dit sans ambiguïté — inventer ces valeurs « serait la faute la
 * plus coûteuse de ce projet ». Le rapport rend donc un POURCENTAGE pondéré,
 * et publie la note officielle d'absence de barème à côté : l'absence est une
 * information, et c'est le produit qui la donne.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * CHAQUE SCORE PORTE SON VOLUME D'ÉVIDENCE
 *
 * Règle du dépôt, structurelle dans `MasteryScore::toPublicArray`. Une section
 * rend donc toujours `asked` à côté de son taux. Une section à 100 % sur deux
 * questions n'est pas une section maîtrisée, et le lecteur doit pouvoir le
 * voir sans avoir à le deviner.
 */
final class SimulationReport
{
    public function __construct(private readonly RemediationPlanner $planner) {}

    /** @return array<string, mixed> */
    public function build(Attempt $attempt, int $limiteOrdonnance = 5): array
    {
        $items = $attempt->items()->with(['response'])->get();

        $sections = $this->parSection($attempt, $items);

        return [
            'sections' => $sections,
            'score' => $this->scorePondere($sections),
            'brut' => [
                'asked' => $attempt->item_count,
                'answered' => $items->filter(fn ($i) => $i->response !== null)->count(),
                'correct' => $attempt->correct_count,
            ],
            /* LE RENVOI VERS L'ORDONNANCE — c'est ce qui rend le rapport
             * actionnable. Une note sans « et maintenant ? » n'apprend rien, et
             * l'ordonnance est déjà le service qui répond à cette question pour
             * tout le reste du produit. On la réemploie, on ne la refait pas. */
            'ordonnance' => $attempt->exam !== null
                ? $this->planner->prioritize($attempt->user, $attempt->exam, $limiteOrdonnance)->values()
                : collect(),
        ];
    }

    /**
     * Le détail par section : un sous-domaine pondéré de l'épreuve.
     *
     * On regroupe par `competency_node_id` porté par l'ITEM et non par la
     * question : c'est celui qui a été figé à la composition. Une question
     * rerattachée depuis n'a pas à déplacer une section déjà passée.
     *
     * @param  Collection<int, AttemptItem>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function parSection(Attempt $attempt, Collection $items): Collection
    {
        $noeuds = CompetencyNode::whereIn('id', $items->pluck('competency_node_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        return $items
            ->groupBy('competency_node_id')
            ->map(function (Collection $groupe, $nodeId) use ($noeuds) {
                $noeud = $noeuds->get($nodeId);

                $posees = $groupe->count();
                $repondues = $groupe->filter(fn ($i) => $i->response !== null)->count();
                $justes = $groupe->filter(fn ($i) => $i->response?->is_correct === true)->count();

                return [
                    'node_uuid' => $noeud?->uuid,
                    'code' => $noeud?->code,
                    'name' => $noeud?->localized('name'),
                    /* Le poids OFFICIEL du domaine, servi tel quel : c'est lui
                     * qui justifie que ce rapport porte une note. */
                    'weight_percent' => $noeud?->weight_percent !== null
                        ? (float) $noeud->weight_percent
                        : null,
                    'asked' => $posees,
                    'answered' => $repondues,
                    'correct' => $justes,
                    // Le taux de la section, sur ce qui a été POSÉ : une
                    // question laissée blanche est une question ratée.
                    'rate' => $posees > 0 ? round($justes / $posees, 4) : null,
                ];
            })
            ->sortByDesc('weight_percent')
            ->values();
    }

    /**
     * La note blanche : moyenne des taux de section, pondérée par les poids
     * officiels.
     *
     * LE DÉNOMINATEUR NE COMPTE QUE LES SECTIONS RÉELLEMENT POSÉES. Une section
     * pondérée mais absente de la série — la banque n'avait pas de question —
     * ne doit pas peser comme un zéro : le candidat n'a pas échoué à ce qu'on
     * ne lui a pas demandé. On rend donc `weight_covered`, la part du barème
     * officiel que la série a effectivement couverte, à côté du score.
     *
     * Sans cette part, le score serait un chiffre sans son assiette — et le
     * produit interdit qu'un score sorte sans son volume d'évidence.
     *
     * @param  Collection<int, array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    private function scorePondere(Collection $sections): array
    {
        $pesees = $sections->filter(
            fn (array $s) => $s['weight_percent'] !== null && $s['asked'] > 0
        );

        $sommeDesPoids = (float) $pesees->sum('weight_percent');

        if ($sommeDesPoids <= 0.0) {
            /* Aucune section pondérée servie : on ne rend PAS zéro. L'absence
             * ne vaut pas un score nul — elle ne dit rien, et un zéro
             * affiché serait une affirmation fausse. */
            return [
                'weighted_percent' => null,
                'weight_covered' => 0.0,
                'sections_scored' => 0,
            ];
        }

        $cumul = $pesees->reduce(
            fn (float $porte, array $s) => $porte + ((float) $s['weight_percent'] * (float) $s['rate']),
            0.0
        );

        return [
            'weighted_percent' => round(($cumul / $sommeDesPoids) * 100, 1),
            /* Part du barème officiel effectivement couverte par la série.
             * 100.0 = tous les domaines pondérés ont été interrogés. */
            'weight_covered' => round($sommeDesPoids, 2),
            'sections_scored' => $pesees->count(),
        ];
    }
}
