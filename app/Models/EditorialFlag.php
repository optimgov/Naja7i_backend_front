<?php

namespace App\Models;

use App\Enums\EditorialFlagKind;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ce qu'un expert signale en travaillant — le lot Q2.
 *
 * LA RELECTURE LA MOINS CHÈRE DU PROJET. Les experts lisent 1 413 questions
 * une par une ; ils voient les énoncés douteux, les options ambiguës, les
 * corrigés contestables. Recueillir cela en commentaire libre le rend
 * inexploitable — cinquante phrases ne se dépouillent pas — et le perdre
 * reviendrait à refaire cette relecture plus tard, plus cher.
 *
 * Un GENRE nommé, donc, et le texte libre en supplément. En ajout seul : un
 * signalement retiré est un désaccord effacé.
 */
class EditorialFlag extends Model
{
    use HasPublicUuid;

    public $timestamps = false;

    protected $fillable = ['prepared_question_id', 'actor_id', 'kind', 'note', 'occurred_at'];

    protected $hidden = ['id', 'prepared_question_id', 'actor_id'];

    protected function casts(): array
    {
        return ['kind' => EditorialFlagKind::class, 'occurred_at' => 'datetime'];
    }

    public function preparedQuestion(): BelongsTo
    {
        return $this->belongsTo(PreparedQuestion::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
