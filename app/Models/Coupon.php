<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un coupon cadeau : un code qui ouvre un plan, à validation humaine.
 *
 * IL N'OUVRE AUCUN DROIT PAR LUI-MÊME. Saisir un code crée une commande EN
 * ATTENTE ; c'est un membre de l'équipe qui l'honore. Le coupon est donc un
 * titre à faire valoir, pas une clé — et cette distinction est ce qui rend le
 * moyen utilisable sans prestataire de paiement : quelqu'un a encaissé un
 * virement, quelqu'un le confirme.
 *
 * `created_by` est ici. `validated_by` est sur la COMMANDE : un coupon de
 * cinquante usages est validé cinquante fois, et la piste d'audit veut savoir
 * qui a ouvert CE droit-là.
 */
class Coupon extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'code', 'plan_id', 'valid_from', 'valid_until',
        'max_uses', 'used_count', 'created_by', 'note', 'status',
    ];

    protected $hidden = ['id', 'plan_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'max_uses' => 'integer',
            'used_count' => 'integer',
        ];
    }

    /**
     * L'ALPHABET EXCLUT CE QUI SE CONFOND À L'ORAL ET À L'ŒIL.
     *
     * Ni O ni 0, ni I ni 1, ni L. Un coupon se dicte au téléphone et se recopie
     * d'une capture d'écran : chaque caractère ambigu est un appel au support.
     *
     * Douze caractères sur 27 symboles font ~57 bits — non devinable, ce qui
     * compte pour un titre qui vaut de l'argent. Le préfixe `NJ7` n'ajoute
     * aucune entropie : il rend le code reconnaissable dans un fil de
     * discussion.
     */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ2345679';

    public static function engendrer(): string
    {
        $corps = '';

        for ($i = 0; $i < 12; $i++) {
            $corps .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        /* `str_split` et non `Str::split` : ce dernier découpe PAR un motif, et
         * `/(.{4})/` rendait des chaînes vides — le générateur produisait
         * « NJ7- » à chaque appel. Trouvé par le test d'unicité sur cinquante
         * tirages, qui n'en voyait qu'un. */
        return 'NJ7-'.implode('-', str_split($corps, 4));
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Utilisable MAINTENANT ?
     *
     * Le statut n'est pas la seule source : un coupon peut être `actif` en base
     * et périmé par sa date. On répond sur les FAITS — dates et compteur — et
     * `status` sert à la lecture humaine en back-office, pas à la décision.
     * Deux sources qui peuvent diverger, une seule qui tranche.
     */
    public function estUtilisable(): bool
    {
        if ($this->status === 'revoque') {
            return false;
        }

        if ($this->used_count >= $this->max_uses) {
            return false;
        }

        if ($this->valid_from->isFuture()) {
            return false;
        }

        return $this->valid_until === null || $this->valid_until->isFuture();
    }

    /** Pourquoi il n'est pas utilisable — pour un message précis, pas générique. */
    public function motifDeRefus(): ?string
    {
        if ($this->status === 'revoque') {
            return 'revoque';
        }
        if ($this->used_count >= $this->max_uses) {
            return 'epuise';
        }
        if ($this->valid_from->isFuture()) {
            return 'pas_encore_valide';
        }
        if ($this->valid_until !== null && ! $this->valid_until->isFuture()) {
            return 'expire';
        }

        return null;
    }
}
