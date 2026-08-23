<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Support\Facades\DB;

/**
 * La difficulté OBSERVÉE — lot Q2, pas 3.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * DEUX VALEURS, JAMAIS FUSIONNÉES
 *
 * La **déclarée** est l'hypothèse de l'expert, posée à la qualification. L'
 * **observée** est dérivée du taux de réussite réel. L'écart entre les deux est
 * une information sur la QUESTION — elle est plus dure qu'on ne croyait — *et*
 * sur l'EXPERT — il surestime les candidats. Les fusionner détruirait les deux
 * : on aurait un seul nombre qui ne dit ni ce qu'on pensait, ni ce qui s'est
 * passé, et personne ne saurait plus lequel des deux il regarde.
 *
 * C'est pourquoi rien ici n'écrit dans `declared_difficulty`. Cette classe
 * LIT ; elle ne corrige personne.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * SOUS LE SEUIL, ON NE REND PAS UN NOMBRE
 *
 * Un taux de réussite sur sept réponses n'est pas une mesure, c'est un hasard
 * mis en forme. Le rendre comme un cran donnerait à l'expert une contradiction
 * apparente — « vous aviez dit 3, c'est 5 » — fondée sur rien, et il
 * corrigerait sa déclaration pour suivre du bruit.
 *
 * On rend donc `significative: false` et AUCUN cran. C'est la même règle que
 * `MasteryScore` applique au candidat depuis le PAS-8 : un score ne sort jamais
 * sans son volume d'évidence, et sous le seuil il ne sort pas du tout.
 */
final class DifficulteObservee
{
    /**
     * Le nombre de réponses en dessous duquel on ne conclut rien.
     *
     * Trente, et c'est une valeur d'architecte comme le 0,35 de DET-19 : elle
     * arbitre entre « attendre trop » et « conclure trop tôt ». Elle vit ici
     * plutôt qu'en base parce que rien ne l'a encore contestée (DET-95).
     */
    public const SEUIL_SIGNIFICATIF = 30;

    /**
     * Les bornes de taux de réussite, du cran 1 au cran 5.
     *
     * Lues à l'envers de l'intuition : PLUS on réussit, MOINS c'est difficile.
     * Un acquis de base est réussi par presque tout le monde ; une question
     * discriminante par une minorité — c'est sa définition.
     *
     * @var array<int, array{0: float, 1: float}>
     */
    private const BORNES = [
        1 => [0.85, 1.01],
        2 => [0.70, 0.85],
        3 => [0.50, 0.70],
        4 => [0.30, 0.50],
        5 => [-0.01, 0.30],
    ];

    /**
     * Ce que les candidats ont réellement fait de cette question.
     *
     * @return array{significative: bool, tentatives: int, taux_reussite: ?float, cran: ?int}
     */
    public function pour(Question $question): array
    {
        $mesure = DB::table('responses')
            ->join('attempt_items', 'attempt_items.id', '=', 'responses.attempt_item_id')
            ->where('attempt_items.question_id', $question->getKey())
            /* Seules les réponses DONNÉES comptent. Une question servie et
             * sautée ne dit rien de sa difficulté — elle dit du temps qui
             * manquait, ou un candidat qui a renoncé, et confondre les deux
             * ferait passer les questions longues pour des questions dures. */
            ->whereNotNull('responses.selected_option_id')
            ->selectRaw('count(*) as total, count(*) filter (where responses.is_correct) as justes')
            ->first();

        $total = (int) ($mesure->total ?? 0);

        if ($total < self::SEUIL_SIGNIFICATIF) {
            return [
                'significative' => false,
                'tentatives' => $total,
                /* NI TAUX NI CRAN. Rendre le taux « pour information » suffirait
                 * à ce qu'il soit lu comme une mesure : un nombre affiché est
                 * un nombre cru. */
                'taux_reussite' => null,
                'cran' => null,
            ];
        }

        $taux = round((int) $mesure->justes / $total, 4);

        return [
            'significative' => true,
            'tentatives' => $total,
            'taux_reussite' => $taux,
            'cran' => $this->cranPour($taux),
        ];
    }

    /** Le cran correspondant à un taux de réussite. */
    public function cranPour(float $taux): int
    {
        foreach (self::BORNES as $cran => [$bas, $haut]) {
            if ($taux >= $bas && $taux < $haut) {
                return $cran;
            }
        }

        return 3;   // inatteignable : les bornes couvrent [0, 1]
    }

    /**
     * L'écart entre ce que l'expert a prédit et ce qui s'est passé.
     *
     * `null` quand l'un des deux manque — et c'est le cas le plus fréquent au
     * démarrage. Un écart calculé contre une observée non significative serait
     * un reproche fondé sur du bruit.
     */
    public function ecart(Question $question, ?int $declaree): ?int
    {
        $observee = $this->pour($question);

        if ($declaree === null || ! $observee['significative']) {
            return null;
        }

        return $observee['cran'] - $declaree;
    }
}
