<?php

namespace App\Filament\Resources\QuotaProfiles\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Un refus du service doit se lire SOUS LE CHAMP qu'il refuse.
 *
 * `QuotaProfileService` ignore Filament, et c'est ainsi qu'il doit rester : il
 * nomme ses champs (`min_value`, `min_justification`), pas leur chemin d'état.
 * Filament, lui, publie l'état du formulaire sous `data.*` — un message rendu
 * sur `min_justification` n'atteindrait aucun champ et apparaîtrait comme une
 * erreur générique, au moment précis où l'admin pédagogique a besoin de savoir
 * QUELLE borne on lui refuse.
 *
 * Le relais est donc un adaptateur d'affichage, jamais une règle. Même
 * mécanisme que `MonDossier::relayErrors()`.
 */
trait RelaieLesRefusDuService
{
    private function relayerSurLeFormulaire(ValidationException $exception): never
    {
        throw ValidationException::withMessages(
            collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $champ): array => ["data.{$champ}" => $messages])
                ->all()
        );
    }
}
