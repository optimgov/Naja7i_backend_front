<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

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
class Source extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'code', 'kind', 'title_fr', 'title_ar', 'authority_fr', 'authority_ar',
        'session_label', 'languages', 'location_note_fr', 'location_note_ar', 'url',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['languages' => 'array'];
    }

    public function isOfficial(): bool
    {
        return $this->kind === 'descriptif_officiel' || $this->kind === 'texte_reglementaire';
    }
}
