<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une commande : l'intention d'acquérir un plan, et sa suite.
 *
 * Table d'ACTIVITÉ, donc isolée par tenant (ADR-0002).
 *
 * LE MONTANT EST FIGÉ ICI, pas lu depuis le plan. Un prix change ; une commande
 * passée ne change pas. Relire le prix courant pour afficher un historique
 * réécrirait le passé — sur de la monnaie, ce n'est pas une imprécision.
 *
 * UNE COMMANDE HONORÉE PRODUIT DES OCTROIS, et c'est le seul chemin. Aucun
 * octroi d'achat n'est posé à la main : `AccessGrant` reste la source de vérité
 * des droits, et le mur payant continue de la lire sans savoir qu'un abonnement
 * existe.
 */
class Order extends Model
{
    use BelongsToTenant, HasPublicUuid;

    protected $fillable = [
        'user_id', 'plan_id', 'coupon_id', 'amount_cents', 'currency',
        'external_reference', 'idempotency_key', 'idempotency_fingerprint',
        'status', 'method', 'honored_at', 'validated_by', 'validated_at',
        'refusal_reason',
    ];

    /* L'empreinte est un détail d'implémentation de l'idempotence ; le motif de
     * refus est INTERNE et ne sort jamais vers le candidat (règle de DET-50). */
    protected $hidden = [
        'id', 'tenant_id', 'user_id', 'plan_id', 'coupon_id',
        'idempotency_key', 'idempotency_fingerprint',
        'validated_by', 'refusal_reason',
    ];

    protected function casts(): array
    {
        return [
            'honored_at' => 'datetime',
            'validated_at' => 'datetime',
            'amount_cents' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function validePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function scopeEnAttente(Builder $query): Builder
    {
        return $query->where('status', 'en_attente');
    }

    public function estHonoree(): bool
    {
        return $this->status === 'honoree';
    }
}
