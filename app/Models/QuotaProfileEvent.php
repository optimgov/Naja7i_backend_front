<?php

namespace App\Models;

use App\Enums\QuotaProfileEventType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Journal en ajout seul des gestes posés sur un profil de quota. */
final class QuotaProfileEvent extends Model
{
    use HasPublicUuid;

    public $timestamps = false;

    /** Aucun formulaire ne peut fabriquer un événement d'audit. */
    protected $guarded = ['*'];

    protected $hidden = ['id', 'quota_profile_id', 'actor_id'];

    protected function casts(): array
    {
        return [
            'event_type' => QuotaProfileEventType::class,
            'before' => 'array',
            'after' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function quotaProfile(): BelongsTo
    {
        return $this->belongsTo(QuotaProfile::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
