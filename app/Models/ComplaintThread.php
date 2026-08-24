<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ComplaintThread extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const CATEGORIES = ['technical', 'pedagogical', 'account', 'payment', 'other'];

    public const STATUSES = ['waiting_staff', 'waiting_candidate'];

    protected $fillable = [
        'candidate_id', 'category', 'subject', 'status', 'last_message_at',
    ];

    protected $hidden = ['id', 'tenant_id', 'candidate_id'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'candidate_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ComplaintMessage::class)->orderBy('id');
    }
}
