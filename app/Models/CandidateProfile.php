<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ce qu'un candidat DÉCLARE préparer — DET-42.
 *
 * SEULE SOURCE DE VÉRITÉ SUR L'ÉPREUVE PRÉPARÉE. La déduction par la tentative
 * la plus récente ne subsiste nulle part côté serveur : elle reste possible
 * côté frontend, mais comme une SUGGESTION à confirmer, jamais comme une
 * réponse. Deux vérités sur cette question se contrediraient tôt ou tard.
 *
 * Un profil absent n'est pas une erreur : c'est un candidat qui n'a pas encore
 * choisi. C'est pourquoi rien ici n'est obligatoire, et pourquoi le contrôleur
 * rend des champs nuls plutôt qu'une 404.
 */
class CandidateProfile extends Model
{
    use BelongsToTenant, HasPublicUuid;

    /**
     * `user_id` n'y figure pas : le profil est toujours celui du candidat
     * authentifié, et le laisser assignable ouvrirait la porte à l'écriture
     * dans le profil d'autrui par une charge utile.
     */
    protected $fillable = ['exam_id', 'objective', 'target_date'];

    protected $hidden = ['id', 'tenant_id', 'user_id', 'exam_id'];

    protected function casts(): array
    {
        return ['target_date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** L'épreuve préparée, ou null tant que rien n'est déclaré. */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }
}
