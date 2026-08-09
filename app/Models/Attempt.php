<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une tentative : diagnostic, entraînement, simulation ou question miroir.
 * Table d'ACTIVITÉ, donc isolée par tenant (ADR-0002).
 */
class Attempt extends Model
{
    use BelongsToTenant, HasPublicUuid;

    protected $fillable = [
        'user_id', 'exam_id', 'specialty_id', 'locale', 'idempotency_key',
        'kind', 'status', 'started_at', 'expires_at', 'submitted_at',
        'item_count', 'answered_count', 'correct_count',
    ];

    protected $hidden = ['id', 'tenant_id', 'user_id', 'exam_id', 'specialty_id', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AttemptItem::class)->orderBy('position');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('attempts.status', 'in_progress');
    }

    public function isOpen(): bool
    {
        return $this->status === 'in_progress' && ! $this->hasExpired();
    }

    /** Le temps est décidé par le serveur, jamais par le client. */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function secondsRemaining(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return max(0, now()->diffInSeconds($this->expires_at, false));
    }
}
