<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StaffInvitation extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'user_id', 'invited_by', 'token_hash', 'expires_at', 'consumed_at', 'revoked_at',
    ];

    protected $hidden = ['id', 'user_id', 'invited_by', 'token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
