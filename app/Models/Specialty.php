<?php

namespace App\Models;

use App\Models\Concerns\IsCatalogueEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Specialty extends Model
{
    use IsCatalogueEntry;

    protected $table = 'specialties';

    protected $fillable = [
        'exam_family_id', 'track_id', 'slug', 'name_fr', 'name_ar', 'cycle_fr', 'cycle_ar',
        'description_fr', 'description_ar', 'position', 'status', 'availability', 'published_at',
    ];

    protected $hidden = ['id', 'exam_family_id', 'track_id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(ExamFamily::class, 'exam_family_id');
    }

    /** PAS-4.1 — Le parcours (secondaire, primaire…) auquel la spécialité appartient. */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
