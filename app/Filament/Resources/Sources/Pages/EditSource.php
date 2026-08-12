<?php

namespace App\Filament\Resources\Sources\Pages;

use App\Filament\Resources\Sources\SourceResource;
use App\Models\Source;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Amender une source, et voir immédiatement ce que cela vient de coûter.
 *
 * L'INVALIDATION N'EST PAS APPLIQUÉE ICI, elle est CONSTATÉE. Le déclencheur
 * `sources_verification_invalidee` (PAS-29) annule la vérification quand une
 * colonne porteuse de sens change, quel que soit le chemin d'écriture — cette
 * page, une commande artisan, un `psql` à la main. Elle n'ajoute donc aucune
 * règle : elle compare l'état d'avant à celui d'après et le dit.
 *
 * POURQUOI ON RESTE SUR LA PAGE. Rediriger vers la liste renverrait le
 * rédacteur ailleurs au moment précis où l'information compte. L'encart d'état
 * du formulaire affiche déjà la vérité — `SourceObserver` a relu les deux
 * colonnes que le déclencheur venait de modifier en base sans toucher à
 * l'instance PHP — et la notification nomme le nombre de citations
 * rétrogradées, que l'encart ne peut pas connaître.
 */
class EditSource extends EditRecord
{
    protected static string $resource = SourceResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $etaitVerifiee = $record->estVerifiee();
        $citationsAvant = $etaitVerifiee ? $this->citationsVerifiees($record) : 0;

        $record->update($data);

        if ($etaitVerifiee && ! $record->estVerifiee()) {
            $this->annoncerInvalidation($citationsAvant - $this->citationsVerifiees($record));
        }

        return $record;
    }

    /**
     * Pas de redirection : le changement d'état doit se lire à l'endroit où on
     * vient de le provoquer.
     */
    protected function getRedirectUrl(): ?string
    {
        return null;
    }

    private function citationsVerifiees(Source $source): int
    {
        return $source->questions()->wherePivot('verification', 'verified')->count();
    }

    /**
     * Le message dit la CAUSE autant que l'effet.
     *
     * « La vérification a été annulée » sans la raison se lit comme un bug.
     * Avec la liste des colonnes en cause, c'est une règle qu'on comprend et
     * qu'on n'accuse plus.
     */
    private function annoncerInvalidation(int $citationsRetrogradees): void
    {
        Notification::make()
            ->warning()
            ->title('Vérification annulée')
            ->body(
                'Un champ d\'identification a changé : le document contrôlé n\'est plus '
                .'exactement celui qui l\'a été. '
                .($citationsRetrogradees > 0
                    ? $citationsRetrogradees.' citation(s) sont repassées à « non vérifiée ». '
                    : '')
                .'Un nouveau contrôle documentaire est requis depuis la liste des sources.'
            )
            ->persistent()
            ->send();
    }
}
