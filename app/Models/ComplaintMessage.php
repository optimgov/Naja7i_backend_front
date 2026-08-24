<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ComplaintMessage extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public $timestamps = false;

    protected $fillable = [
        'complaint_thread_id', 'sender_id', 'sender_type', 'body',
        'idempotency_key', 'idempotency_fingerprint', 'operation', 'created_at',
    ];

    protected $hidden = [
        'id', 'tenant_id', 'complaint_thread_id', 'sender_id',
        'idempotency_key', 'idempotency_fingerprint', 'operation',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ComplaintThread::class, 'complaint_thread_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
