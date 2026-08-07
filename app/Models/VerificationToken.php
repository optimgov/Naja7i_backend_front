<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jeton opaque à usage unique. Stocké haché, jamais en clair.
 * Table globale, comme le compte auquel elle se rapporte.
 */
class VerificationToken extends Model
{
    public const PURPOSE_EMAIL = 'email_verification';

    protected $fillable = ['user_id', 'token_hash', 'purpose', 'expires_at', 'consumed_at'];

    protected $hidden = ['id', 'user_id', 'token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
