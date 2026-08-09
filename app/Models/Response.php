<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La réponse d'un candidat à un item.
 *
 * `confidence` (Certitude+, F02) est déclarée AVANT la correction. Elle
 * distingue quatre situations que le score seul confond :
 *   juste + sûr      → acquis
 *   juste + hasard   → non acquis, masqué par la chance
 *   faux  + sûr      → erreur profonde, la plus coûteuse à laisser passer
 *   faux  + hésitant → notion fragile
 */
class Response extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const CONFIDENCES = ['sure', 'hesitant', 'guess'];

    protected $fillable = [
        'attempt_item_id', 'selected_option_id', 'confidence',
        'is_correct', 'answered_at', 'client_reported_at', 'elapsed_ms', 'cause_revealed',
    ];

    protected $hidden = ['id', 'tenant_id', 'attempt_item_id', 'selected_option_id'];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'cause_revealed' => 'boolean',
            'answered_at' => 'datetime',
            'client_reported_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AttemptItem::class, 'attempt_item_id');
    }

    public function selectedOption(): BelongsTo
    {
        return $this->belongsTo(QuestionOption::class, 'selected_option_id');
    }

    /** Juste par chance : le cas que le score seul rend invisible. */
    public function isLuckyGuess(): bool
    {
        return $this->is_correct === true && $this->confidence === 'guess';
    }

    /** Faux avec certitude : l'erreur la plus coûteuse à ne pas traiter. */
    public function isConfidentError(): bool
    {
        return $this->is_correct === false && $this->confidence === 'sure';
    }
}
