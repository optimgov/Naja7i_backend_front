<?php

namespace App\Services;

use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\MasteryScore;
use App\Models\Remediation;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Ordonnance Najah (F06) — que réviser, et dans quel ordre.
 *
 * Le classement croise trois facteurs, et l'ordre compte :
 *
 *  - **Le poids officiel du domaine.** Travailler un domaine à 5 % avant un
 *    domaine à 40 % est un mauvais emploi du temps du candidat, même si le
 *    score y est plus bas.
 *  - **L'écart de maîtrise.** Ce qui manque, pas ce qui est acquis.
 *  - **La nature des erreurs.** Une erreur commise avec certitude vaut plus
 *    qu'une erreur hésitante : le candidat ne sait pas qu'il ne sait pas, donc
 *    il ne réviserait jamais ce point de lui-même.
 *
 * Ce service ne produit AUCUNE probabilité de réussite (METHODE §7.3). Il dit
 * quoi faire ensuite, jamais si le candidat réussira.
 */
final class RemediationPlanner
{
    /** Une erreur avec certitude compte double dans l'urgence. */
    private const FACTEUR_ERREUR_AVEUGLE = 2.0;

    /** Une réussite au hasard signale une lacune que le score sous-estime. */
    private const FACTEUR_CHANCE = 1.0;

    /**
     * Un domaine jamais évalué est un angle mort, pas une lacune démontrée.
     *
     * Le compter comme un échec total (facteur 1,0) reviendrait à dire qu'un
     * domaine non testé est aussi déficient qu'un domaine où le candidat vient
     * d'obtenir zéro. L'effet est concret et mauvais : après un premier
     * diagnostic partiel, la quasi-totalité des domaines est non évaluée, et
     * l'ordonnance recommanderait d'abord ce que le candidat n'a jamais passé,
     * en enterrant les domaines où il a démontré sa faiblesse.
     *
     * L'ADR-0017 §5 classe par poids officiel PUIS par écart de maîtrise.
     * L'écart d'un domaine jamais évalué est inconnu, pas maximal : il entre
     * donc à moitié d'écart, ce qui le maintient dans l'ordonnance et le classe
     * par poids parmi ses pairs, mais derrière une faiblesse démontrée de poids
     * comparable.
     *
     * Valeur d'architecte, à réétalonner comme le 0,35 de la certitude (DET-22).
     */
    private const FACTEUR_JAMAIS_EVALUE = 0.5;

    /**
     * Une question servie et sautée : le même raisonnement, appliqué non plus
     * au domaine entier mais à la part qu'il a esquivée.
     *
     * Sans elle, la plateforme récompensait l'évitement. Deux candidats sur la
     * même série de dix, cinq bonnes réponses chacun :
     *
     *     répond faux aux cinq autres → score 50, écart 0,5 → urgence 10
     *     saute les cinq autres       → score 100, écart 0   → urgence  0
     *
     * Le second sortait de l'ordonnance en n'affrontant pas ses questions.
     * C'est l'exact contraire de la promesse : `DiagnosticComposer` répartit
     * selon les poids officiels pour ne pas flatter un candidat fort sur un
     * domaine mineur, et l'ordonnance flattait celui qui n'avait pas répondu.
     *
     * MÊME VALEUR QUE `FACTEUR_JAMAIS_EVALUE`, ET C'EST DÉLIBÉRÉ. Sur la part
     * sautée, on ne sait rien — exactement comme sur un domaine jamais servi.
     * L'écart y est inconnu, pas maximal. Ce qui distingue les deux cas n'est
     * pas ce qu'on sait, c'est ce qu'on en dit : le motif, que le candidat
     * lit. Un domaine à moitié sauté reste donc classé sous un domaine jamais
     * servi de même poids (0,25 × poids contre 0,5 × poids) — on en sait
     * encore moins sur le second.
     *
     * DEUX CONSTANTES SÉPARÉES malgré la valeur commune : elles arbitrent des
     * questions différentes — « faire découvrir » contre « faire corriger »
     * pour l'une, « ne pas récompenser l'évitement » sans « punir plus que
     * l'erreur » pour l'autre. Elles divergeront au réétalonnage.
     *
     * BORNE HAUTE MESURÉE, pas devinée : au-delà de 1,0, le sauteur de
     * l'exemple passe DEVANT celui qui a répondu faux. L'erreur démontrée
     * reste le signal le plus sûr du produit ; rien ne doit la dépasser.
     * La sonde de `MaitriseTest` tient cette borne.
     *
     * Valeur d'architecte, à réétalonner avec DET-19 et DET-22.
     */
    private const FACTEUR_QUESTION_SAUTEE = 0.5;

    /**
     * Priorités de révision, de la plus urgente à la moins urgente.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function prioritize(User $user, Exam $exam, int $limit = 5): Collection
    {
        $noeuds = CompetencyNode::where('exam_id', $exam->id)
            ->whereNotNull('weight_percent')
            ->where('depth', '>', 0)
            ->get()
            ->keyBy('id');

        if ($noeuds->isEmpty()) {
            return collect();
        }

        $scores = MasteryScore::where('user_id', $user->id)
            ->whereIn('competency_node_id', $noeuds->keys())
            ->get()
            ->keyBy('competency_node_id');

        $lignes = collect();

        foreach ($noeuds as $noeud) {
            $score = $scores->get($noeud->id);
            $poids = (float) $noeud->weight_percent;

            /*
             * Aucune ligne : le domaine n'a JAMAIS été servi. C'est un angle
             * mort. Il entre dans l'ordonnance à écart partiel (voir
             * FACTEUR_JAMAIS_EVALUE) et avec un motif distinct — le candidat
             * doit savoir qu'on lui propose de découvrir, pas de corriger.
             *
             * Une ligne existe désormais dès qu'une question a été SERVIE,
             * même si aucune n'a été répondue : ce cas-là n'est plus un angle
             * mort, c'est un évitement, et il est traité plus bas.
             */
            if ($score === null) {
                $lignes->push($this->ligne(
                    $noeud, $score, $poids,
                    urgence: $poids * self::FACTEUR_JAMAIS_EVALUE,
                    motif: 'jamais_evalue'
                ));

                continue;
            }

            /*
             * L'écart se compose de deux parts, au prorata de ce que le
             * candidat a affronté :
             *
             *  - sur ce qu'il a répondu, l'écart MESURÉ ;
             *  - sur ce qu'il a sauté, un écart inconnu à facteur partiel.
             *
             * Quand rien n'est sauté, la seconde part est nulle et le calcul
             * est mot pour mot celui d'avant. Le correctif ne déplace donc
             * aucun domaine entièrement répondu.
             */
            $servies = $score->answered_count + $score->skipped_count;
            $partSautee = $servies > 0 ? $score->skipped_count / $servies : 0.0;

            /* Score nul = évidence insuffisante : l'écart n'est pas nul, il
             * est inconnu — même traitement qu'un domaine jamais évalué. */
            $ecartMesure = $score->score !== null
                ? max(0, 100 - $score->score) / 100
                : self::FACTEUR_JAMAIS_EVALUE;

            $ecart = ($ecartMesure * (1 - $partSautee))
                   + (self::FACTEUR_QUESTION_SAUTEE * $partSautee);

            $bonus = ($score->confident_error_count * self::FACTEUR_ERREUR_AVEUGLE)
                   + ($score->lucky_guess_count * self::FACTEUR_CHANCE);

            $urgence = ($poids * $ecart) + $bonus;

            $lignes->push($this->ligne(
                $noeud, $score, $poids, $urgence,
                motif: $this->motif($score, $partSautee, $ecartMesure)
            ));
        }

        return $lignes->sortByDesc('urgency')->take($limit)->values();
    }

    /**
     * Le motif est un texte lisible, pas un code : le candidat doit
     * comprendre pourquoi ce domaine lui est proposé. Une recommandation sans
     * raison est une injonction, et le produit refuse d'en donner.
     */
    private function motif(MasteryScore $score, float $partSautee, float $ecartMesure): string
    {
        return match (true) {
            $score->confident_error_count > 0 => 'erreurs_avec_certitude',
            $score->lucky_guess_count > 0 => 'reussites_au_hasard',

            /*
             * Le motif dit POURQUOI ce domaine est proposé. Il bascule sur
             * l'évitement quand l'évitement est ce qui pèse le plus dans
             * l'urgence — pas dès la première question sautée, sinon une
             * sautée sur vingt effacerait un motif plus juste.
             *
             * Le seuil est le calcul lui-même, non une constante de plus :
             * on compare la part sautée à la part mesurée, telles qu'elles
             * entrent dans l'écart quelques lignes plus haut.
             */
            self::FACTEUR_QUESTION_SAUTEE * $partSautee
                > $ecartMesure * (1 - $partSautee) => 'questions_sautees',

            /* Servi, sous le seuil d'évidence, et rien de sauté : on ne sait
             * toujours rien de ce domaine. */
            $score->score === null => 'jamais_evalue',

            $score->evidence === 'low' => 'evidence_faible',
            $score->score < 50 => 'maitrise_faible',
            default => 'consolidation',
        };
    }

    /** @return array<string, mixed> */
    private function ligne(
        CompetencyNode $noeud,
        ?MasteryScore $score,
        float $poids,
        float $urgence,
        string $motif,
    ): array {
        return [
            'node_uuid' => $noeud->uuid,
            'node_code' => $noeud->code,
            'node_name' => $noeud->localized('name'),
            'weight_percent' => $poids,
            'score' => $score?->isDisplayable() ? round($score->score, 1) : null,
            'evidence' => $score->evidence ?? 'insufficient',
            'answered_count' => $score->answered_count ?? 0,
            'answers_missing' => $score?->answersMissing() ?? MasteryScore::SEUIL_FAIBLE,
            /* Servi puis laissé. Le candidat doit pouvoir vérifier lui-même
             * ce que le motif `questions_sautees` lui affirme. */
            'skipped_count' => $score->skipped_count ?? 0,
            'confident_errors' => $score->confident_error_count ?? 0,
            'lucky_guesses' => $score->lucky_guess_count ?? 0,
            'reason' => $motif,
            'urgency' => round($urgence, 3),
            'remediation' => $this->remediation($noeud),
        ];
    }

    /** @return array<string, mixed>|null */
    private function remediation(CompetencyNode $noeud): ?array
    {
        $remediation = Remediation::where('competency_node_id', $noeud->id)
            ->where('locale', app()->getLocale())
            ->where('status', 'published')
            ->first();

        if ($remediation === null) {
            return null;   // pas de ressource : on le dit, on n'en invente pas
        }

        return [
            'uuid' => $remediation->uuid,
            'title' => $remediation->title,
            'estimated_minutes' => $remediation->estimated_minutes,
        ];
    }

    /**
     * Résumé par épreuve, destiné à l'écran de résultat.
     *
     * @return array<string, mixed>
     */
    public function examSummary(User $user, Exam $exam): array
    {
        $racines = CompetencyNode::where('exam_id', $exam->id)->whereNull('parent_id')->get();

        $scores = MasteryScore::where('user_id', $user->id)
            ->whereIn('competency_node_id', $racines->pluck('id'))
            ->get()
            ->keyBy('competency_node_id');

        return [
            'exam' => [
                'code' => $exam->code,
                'name' => $exam->localized('name'),
                'coefficient' => $exam->coefficient,
            ],
            'domains' => $racines->map(function (CompetencyNode $racine) use ($scores) {
                $score = $scores->get($racine->id);

                return [
                    'code' => $racine->code,
                    'name' => $racine->localized('name'),
                    'weight_percent' => (float) $racine->weight_percent,
                    'score' => $score?->isDisplayable() ? round($score->score, 1) : null,
                    'evidence' => $score->evidence ?? 'insufficient',
                    'answered_count' => $score->answered_count ?? 0,
                ];
            })->values()->all(),
        ];
    }
}
