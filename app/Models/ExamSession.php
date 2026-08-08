<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une édition datée d'un concours.
 *
 * `dates_confirmed` n'est pas un détail : les dates circulent sur les réseaux
 * bien avant leur publication officielle. Afficher une date non confirmée sans
 * le dire reviendrait à reprendre une rumeur à notre compte, sur un sujet où
 * une erreur coûte cher au candidat.
 */
class ExamSession extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'exam_family_id', 'label_fr', 'label_ar', 'year',
        'registration_opens_on', 'registration_closes_on',
        'written_exam_on', 'oral_exam_on', 'results_on',
        'dates_confirmed', 'source_url', 'source_note_fr', 'source_note_ar',
        'status', 'published_at',
    ];

    protected $hidden = ['id', 'exam_family_id'];

    protected function casts(): array
    {
        return [
            'registration_opens_on' => 'date',
            'registration_closes_on' => 'date',
            'written_exam_on' => 'date',
            'oral_exam_on' => 'date',
            'results_on' => 'date',
            'dates_confirmed' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(ExamFamily::class, 'exam_family_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('written_exam_on')->orWhere('written_exam_on', '>=', today());
        });
    }
}
