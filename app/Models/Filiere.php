<?php

namespace App\Models;

use App\Models\Concerns\IsCatalogueEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Premier niveau du catalogue : les « portes » du prototype.
 * Post-baccalauréat · Sciences de l'éducation · Fonction publique.
 */
class Filiere extends Model
{
    use IsCatalogueEntry;

    protected $table = 'filieres';

    protected $fillable = [
        'slug', 'name_fr', 'name_ar', 'tagline_fr', 'tagline_ar',
        'position', 'status', 'availability', 'published_at',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function families(): HasMany
    {
        return $this->hasMany(ExamFamily::class);
    }
}
