<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Observers\SourceObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registre des sources. Une source PROUVE quelque chose de précis — et
 * seulement cela.
 *
 * Distinction que le référentiel CRMEF impose et qui évite une erreur
 * fréquente : un descriptif officiel établit le périmètre et les poids des
 * domaines. Il n'établit PAS la bonne réponse à une question de contenu.
 * D'où deux sources différentes sur une question : celle du blueprint, et
 * celle qui fonde réellement la correction.
 */
#[ObservedBy(SourceObserver::class)]
class Source extends Model
{
    use HasPublicUuid;

    /**
     * `verified_at` et `verified_by` sont HORS de cette liste, et c'est la même
     * discipline que les champs de transition d'une question : le contrôle
     * documentaire ne s'assigne pas en masse. Il passe par
     * `SourceVerificationService`, seul endroit qui enregistre qui et quand.
     */
    protected $fillable = [
        'code', 'kind', 'title_fr', 'title_ar', 'authority_fr', 'authority_ar',
        'session_label', 'languages', 'location_note_fr', 'location_note_ar', 'url',
    ];

    protected $hidden = ['id', 'verified_by'];

    /** Le relecteur qui a contrôlé cette source. */
    public function verificateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function estVerifiee(): bool
    {
        return $this->verified_at !== null;
    }

    protected function casts(): array
    {
        return ['languages' => 'array', 'verified_at' => 'datetime'];
    }

    public function isOfficial(): bool
    {
        return $this->kind === 'descriptif_officiel' || $this->kind === 'texte_reglementaire';
    }
}
