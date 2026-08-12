<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Observers\SourceObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    /**
     * Colonnes dont la modification ANNULE la vérification (PAS-29).
     *
     * LA RÈGLE N'EST PAS ICI. Elle est dans le déclencheur
     * `sources_verification_invalidee`, qui l'applique quel que soit le chemin
     * d'écriture — Filament, artisan, psql. Cette liste ne fait que la DÉCRIRE,
     * pour que le back-office puisse prévenir AVANT l'enregistrement au lieu de
     * laisser découvrir après.
     *
     * Une description peut mentir en dérivant de ce qu'elle décrit. Un test la
     * confronte donc colonne par colonne au comportement réel de la base, dans
     * les deux sens : chacune de ces colonnes annule, et une colonne absente
     * de la liste n'annule pas.
     */
    public const COLONNES_DE_SENS = [
        'code', 'kind', 'title_fr', 'title_ar',
        'authority_fr', 'authority_ar', 'session_label', 'url',
    ];

    /**
     * Les questions qui CITENT cette source — l'inverse de
     * `Question::contentSources()`. Sert au back-office à dire ce qu'une
     * modification va coûter : une source citée par trente questions ne se
     * corrige pas comme une source citée par aucune.
     */
    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'question_sources')
            ->withPivot('locator', 'verification');
    }

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
