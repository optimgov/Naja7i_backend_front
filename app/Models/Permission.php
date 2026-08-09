<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Une capacité d'ACTION dans le back-office.
 *
 * À ne pas confondre avec une capacité PRODUIT (`corrections.cause`, contrat
 * AccessGrant) : la permission dit ce que vous avez le droit de faire, la
 * capacité produit dit ce que vous avez obtenu. Les deux nommages sont
 * volontairement distincts (ADR-0009 §3).
 */
class Permission extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'code', 'domain', 'label_fr', 'label_ar',
        'description_fr', 'description_ar', 'platform_only',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['platform_only' => 'boolean'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function localized(string $field, ?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();

        return $this->getAttribute($field.($locale === 'ar' ? '_ar' : '_fr'))
            ?: $this->getAttribute($field.'_fr');
    }
}
