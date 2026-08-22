<?php

namespace App\Filament\Resources\CompetencyNodes\Pages;

use App\Filament\Resources\CompetencyNodes\CompetencyNodeResource;
use Filament\Resources\Pages\EditRecord;

/**
 * LES CLÉS CACHÉES DOIVENT QUAND MÊME REMPLIR LE FORMULAIRE.
 *
 * `CompetencyNode::$hidden` masque `exam_id` et `parent_id` — à raison : ce
 * sont des identifiants internes, et aucune sérialisation destinée au candidat
 * ne doit les porter. Mais Filament remplit son formulaire depuis
 * `attributesToArray()`, qui applique ce masque : à l'édition, l'épreuve
 * arrivait VIDE, et le formulaire refusait un champ obligatoire dont personne
 * n'avait retiré la valeur.
 *
 * Le défaut se serait vu au premier renommage réel — un écran d'administration
 * qui refuse d'enregistrer sans dire quoi corriger. On réinjecte donc les deux
 * clés ici, à l'endroit exact où le masque les retire.
 */
class EditCompetencyNode extends EditRecord
{
    protected static string $resource = CompetencyNodeResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['exam_id'] = $this->getRecord()->exam_id;
        $data['parent_id'] = $this->getRecord()->parent_id;

        return $data;
    }
}
