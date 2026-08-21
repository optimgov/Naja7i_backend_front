<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une coquille corrigée : l'avant, l'après, l'auteur et le motif.
 *
 * EN LECTURE SEULE DEPUIS PHP. La ligne est écrite par la fonction SQL
 * `corriger_version_editoriale()`, dans la même transaction que le texte
 * qu'elle documente ; l'écrire d'ici permettrait de journaliser une correction
 * qui n'a pas eu lieu, ou de corriger sans journaliser. `$guarded = ['*']` rend
 * le contournement visible plutôt que possible, et le déclencheur
 * `plan_version_editorial_fixes_append_only` tient la même règle en base.
 */
class PlanVersionEditorialFix extends Model
{
    use HasPublicUuid;

    protected $guarded = ['*'];

    public $timestamps = false;

    protected $hidden = ['id', 'plan_version_id', 'actor_id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
