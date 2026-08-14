<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rapport d'un examen blanc, APRÈS clôture uniquement.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LA SEULE SURFACE DU PRODUIT OÙ UN SCORE SE PRÉSENTE COMME UNE NOTE D'ÉPREUVE
 *
 * Et il faut écrire ici POURQUOI, sans quoi la prochaine surface qui voudra une
 * note invoquera ce précédent sans en avoir le droit.
 *
 * DET-31 pose le problème : `correct_count` ne distingue pas le `kind`, donc
 * un 9/10 d'entraînement se sérialise comme un 9/10 de diagnostic. Or une série
 * d'entraînement est CIBLÉE sur un point faible — par construction. Afficher
 * « 90 % » à un candidat qui vient de réviser sa lacune lui ferait croire qu'il
 * est prêt, ce qui est exactement l'erreur que la plateforme prétend éviter.
 *
 * L'examen blanc échappe à DET-31 pour une raison STRUCTURELLE, pas
 * éditoriale, et vérifiable en lisant le composeur : `DiagnosticComposer`
 * répartit les questions selon les `weight_percent` officiels des sous-domaines
 * (ADR-0014), par la méthode des plus forts restes. Un domaine qui pèse 15 % du
 * concours reçoit 15 % des questions. La série REPRODUIT les poids de
 * l'épreuve ; le score qui en sort mesure donc ce que le concours mesurerait,
 * à banque et poids égaux.
 *
 * Le jour où l'on composerait un examen blanc autrement — depuis l'ordonnance,
 * depuis la maîtrise, depuis quoi que ce soit d'autre que les poids officiels —
 * CETTE JUSTIFICATION TOMBE, et la note avec elle. C'est pour cela que le test
 * de mutation existe : composer depuis l'ordonnance fait rougir la répartition.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TROIS CHOSES QUE CE RAPPORT NE DIRA JAMAIS
 *
 * 1. AUCUNE PRÉDICTION DE RÉUSSITE, même dérivée (METHODE §7.3). Ni « vous
 *    seriez admis », ni rang, ni probabilité, ni comparaison du score au seuil.
 *    Le seuil officiel est SERVI TEL QUEL quand le descriptif le donne — c'est
 *    une citation, pas un verdict appliqué au candidat. La différence est
 *    entière : citer « admission à 10/20 » informe ; écrire « vous êtes
 *    au-dessus du seuil » prédit.
 *
 * 2. AUCUNE NOTE SUR 20. Le barème n'est pas public. Les trois épreuves portent
 *    `official_scoring_note` = « Barème détaillé non précisé par le
 *    descriptif » — servi par `localized()` depuis DET-54, donc en arabe quand
 *    la traduction existe — et `official_question_count` est NUL partout. La migration
 *    des blueprints tranche : inventer ces valeurs « serait la faute la plus
 *    coûteuse de ce projet ». Le rapport rend donc un POURCENTAGE PONDÉRÉ, et
 *    publie la note d'absence de barème à côté — l'absence est une information,
 *    et c'est le produit qui la donne plutôt que de laisser le candidat
 *    supposer.
 *
 * 3. AUCUN SCORE SANS SON VOLUME D'ÉVIDENCE. Chaque section porte `asked`, et
 *    le score global porte `weight_covered` — la part du barème officiel que la
 *    série a réellement couverte. Un 100 % sur deux questions n'est pas une
 *    section maîtrisée.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LISTE BLANCHE STRICTE, comme `AttemptQuestionResource`.
 *
 * Ni `is_correct` par item, ni `rationale`, ni `cause` : le rapport donne des
 * AGRÉGATS. La correction question par question a sa propre route, son propre
 * quota F03 et son propre mur payant — elle n'entre pas ici par la bande.
 */
class SimulationReportResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $rapport
     */
    public function __construct(
        $resource,
        private readonly array $rapport,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $blueprint = $this->exam?->currentBlueprint;

        return [
            'uuid' => $this->uuid,
            'kind' => $this->kind,
            /* `expired` ou `submitted` : le candidat doit savoir si le
             * chronomètre l'a arrêté ou s'il a rendu lui-même. Le score est
             * calculé de la même façon dans les deux cas — une épreuve
             * expirée est soumise elle aussi. */
            'status' => $this->status,
            'started_at' => $this->started_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),

            'exam' => $this->exam !== null ? [
                'code' => $this->exam->code,
                'name' => $this->exam->localized('name'),
                'coefficient' => $this->exam->coefficient,
                /* La durée officielle, celle qui a armé le chronomètre. */
                'duration_minutes' => $this->exam->duration_minutes,
            ] : null,

            /* LA NOTE BLANCHE. `weighted_percent` est nul quand aucune section
             * pondérée n'a été servie : l'absence ne vaut pas zéro. */
            'score' => $this->rapport['score'],
            'raw' => $this->rapport['brut'],
            'sections' => $this->rapport['sections'],
            'plan' => $this->rapport['ordonnance'],

            'official' => [
                /* SERVIS TELS QUELS, ou nuls. Ces trois champs restent vides
                 * tant qu'une source officielle ne les établit pas, et le
                 * produit préfère le vide à l'invention. Un client qui les
                 * reçoit nuls doit dire « non précisé », jamais combler. */
                'question_count' => $blueprint?->official_question_count,
                /* `localized()` et non le champ `_fr` en dur (DET-54) : ces deux
                 * citations ont désormais leur colonne arabe, et le repli reste
                 * le français quand la traduction manque. */
                'scoring_note' => $blueprint?->localized('official_scoring_note'),
                'admission_threshold_note' => $blueprint?->localized('official_admission_threshold_note'),
                'blueprint_version' => $blueprint?->version,
            ],

            'meta' => [
                /* Pourquoi une note ici et nulle part ailleurs — le contrat le
                 * dit lui-même, pour que le client n'ait pas à le déduire. */
                'scoring_basis' => __('parcours.simulation_base_de_notation'),
                'not_official_scale' => __('parcours.simulation_bareme_non_officiel'),
                'disclaimer' => __('parcours.aucune_prediction'),
            ],
        ];
    }
}
