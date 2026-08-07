<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une méthode de connexion. Table globale, comme le compte.
 * Un compte ne reste jamais sans identité utilisable (invariant DET-03).
 */
class Identity extends Model
{
    use HasPublicUuid;

    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'last_used_at'];

    protected $hidden = ['id', 'user_id', 'provider_user_id'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
