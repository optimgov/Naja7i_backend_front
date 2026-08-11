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
        'user_id', 'exam_id', 'specialty_id', 'locale',
        'idempotency_key', 'idempotency_fingerprint',
        'kind', 'status', 'started_at', 'expires_at', 'submitted_at',
        'last_activity_at', 'item_count', 'answered_count', 'correct_count',
    ];

    /* L'empreinte est un détail d'implémentation de l'idempotence : la publier
     * apprendrait à un client à la reconstruire, donc à la contourner. */
    protected $hidden = [
        'id', 'tenant_id', 'user_id', 'exam_id', 'specialty_id',
        'idempotency_key', 'idempotency_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * Une tentative naît avec une dernière activité : son ouverture.
     *
     * La colonne est NOT NULL parce que « pas d'activité connue » n'existe pas
     * — ouvrir EST une activité. Mais l'exiger de chaque appelant ferait échouer
     * en base toute création directe, ici pour une valeur qui se déduit
     * toujours. `AttemptService` la pose explicitement ; ce filet couvre les
     * autres chemins, présents et à venir.
     */
    protected static function booted(): void
    {
        static::creating(function (self $attempt) {
            $attempt->last_activity_at ??= $attempt->started_at ?? now();
        });
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
