<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace d'un acte juridique. JAMAIS modifiée ni supprimée : un retrait de
 * consentement marketing crée un événement `marketing_withdrawn`, il n'efface
 * pas l'octroi antérieur. La suite d'événements EST la preuve.
 */
class LegalEvent extends Model
{
    use HasPublicUuid;

    public const TERMS_ACCEPTED = 'terms_accepted';

    public const PRIVACY_ACKED = 'privacy_notice_acknowledged';

    public const MARKETING_GRANTED = 'marketing_granted';

    public const MARKETING_WITHDRAWN = 'marketing_withdrawn';

    protected $fillable = [
        'user_id', 'legal_document_id', 'action', 'channel',
        'ip_prefix', 'user_agent_hmac', 'request_id', 'occurred_at',
    ];

    protected $hidden = ['id', 'user_id', 'legal_document_id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }
}
