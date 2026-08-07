<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Version publiée d'un document juridique, dans une langue donnée.
 * Immuable une fois publiée : un changement de texte crée une nouvelle version.
 */
class LegalDocument extends Model
{
    use HasPublicUuid;

    public const KIND_TERMS = 'terms';

    public const KIND_PRIVACY = 'privacy_notice';

    public const KIND_MARKETING = 'marketing';

    public const KINDS = [self::KIND_TERMS, self::KIND_PRIVACY, self::KIND_MARKETING];

    protected $fillable = [
        'kind', 'version', 'locale', 'title', 'summary',
        'document_url', 'checksum', 'published_at',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    /** Version en vigueur d'un document, dans une langue. */
    public static function current(string $kind, string $locale = 'fr'): self
    {
        return static::where('kind', $kind)
            ->where('locale', $locale)
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->firstOrFail();
    }

    /** Le texte est-il encore provisoire ? Bloque la mise en production. */
    public function isProvisional(): bool
    {
        return str_contains($this->version, 'provisoire');
    }
}
