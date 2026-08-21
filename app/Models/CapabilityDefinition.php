<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Présentation bilingue d'un code fermé par CapabilityRegistry. */
final class CapabilityDefinition extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    /** Le code vient d'une migration ; l'administration n'édite que sa présentation. */
    protected $guarded = ['code'];

    protected function casts(): array
    {
        return ['a_relire' => 'boolean', 'position' => 'integer'];
    }
}
