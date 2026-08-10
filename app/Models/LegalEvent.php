<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Trace d'un acte juridique — strictement en ajout seul.
 *
 * REVUE PAS-2 BLOC-2 : la classe annonçait « jamais modifiée ni supprimée »
 * dans sa documentation, et n'en empêchait rien. Deux garde-fous désormais :
 * un trigger PostgreSQL qui refuse UPDATE et DELETE quel que soit le chemin,
 * et ces gardes applicatives qui produisent un message compréhensible avant
 * même d'atteindre la base.
 *
 * Une preuve juridique altérable n'est pas une preuve.
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

    public static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'Un acte juridique ne se modifie pas. Un changement d\'avis crée un nouvel acte (ADR-0005).'
            );
        });

        static::deleting(function () {
            throw new RuntimeException(
                'Un acte juridique ne se supprime pas : il constitue la preuve opposable (ADR-0005).'
            );
        });
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
