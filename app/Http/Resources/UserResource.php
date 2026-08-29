<?php

namespace App\Http\Resources;

use App\Support\NiveauxAcademiques;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * LISTE BLANCHE stricte des champs exposés.
 *
 * La revue a montré que makeHidden('id') ne suffit pas : il ne protège ni les
 * tableaux manuels, ni le query builder, ni les clés étrangères d'une relation
 * sérialisée. La garantie réelle vient d'ici — on n'énumère que ce qui sort —
 * et du test contractuel récursif qui parcourt chaque réponse JSON.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'academic_level' => $this->academic_level,
            'address' => $this->address,
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'status' => $this->status,
            'email_verified' => $this->email_verified_at !== null,
            'phone_verified' => $this->phone_verified_at !== null,
            'onboarding_complete' => $this->dossierCandidatComplet(),
            /* LE FRONTEND NE DÉDUIT PAS LE STATUT SCOLAIRE, il le lit. La règle
             * vit dans `NiveauxAcademiques` et nulle part ailleurs : deux
             * implémentations d'une même déduction divergent au premier niveau
             * ajouté. */
            'est_lyceen' => NiveauxAcademiques::estLyceen($this->academic_level),
            'roles' => $this->whenLoaded(
                'memberships',
                fn () => $this->memberships->pluck('role.code')->unique()->values()
            ),
            'role_labels' => $this->whenLoaded(
                'memberships',
                fn () => $this->memberships
                    ->pluck('role')
                    ->unique('code')
                    ->map(fn ($role) => $this->locale === 'ar' ? $role->label_ar : $role->label_fr)
                    ->values()
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
