<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un rendez-vous de révision, tel que le candidat le voit.
 *
 * LA CAUSE EST UN CHAMP PAYANT, ICI COMME AILLEURS. Elle est le diagnostic
 * vendu par la fiche F03, et `CorrectionResource` la ferme déjà derrière
 * `cause_locked`. L'exposer librement ici l'offrirait par une autre porte : il
 * suffirait de lire sa liste de révision pour obtenir gratuitement ce que la
 * correction fait payer. Le mur reste donc un CHAMP, jamais une route — la
 * liste s'affiche pour tout le monde, la cause seule se ferme.
 *
 * LE QUOTA N'EST PAS DÉCOMPTÉ ICI, et c'est délibéré. Consommer une des deux
 * révélations gratuites parce que le candidat a ouvert un écran de liste les
 * brûlerait sans qu'il ait rien demandé. Le décompte reste où il a un sens :
 * sur une correction, item par item.
 *
 * Ce qui reste visible suffit à agir : la compétence, depuis quand c'est dû, et
 * si l'erreur avait été commise AVEC certitude — ce dernier point étant celui
 * que le candidat ne réviserait jamais de lui-même.
 */
class ReviewScheduleResource extends JsonResource
{
    public function __construct($resource, private readonly bool $causeVisible)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'competency' => [
                'code' => $this->node?->code,
                'name' => $this->node?->localized('name'),
            ],
            'cause' => $this->causeVisible ? $this->cause : null,
            'cause_locked' => ! $this->causeVisible,
            /* Index dans la table des intervalles, pas un nombre de jours : il
             * survit à un réétalonnage des paliers. */
            'palier' => $this->palier,
            'due_on' => $this->due_on?->toDateString(),
            'blind_error' => $this->blind_error,
            'last_reviewed_at' => $this->last_reviewed_at?->toIso8601String(),
        ];
    }
}
