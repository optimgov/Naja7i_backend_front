<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Un nœud de l'arbre de compétences. La profondeur est libre (ADR-0012) :
 * c'est le profil de la famille qui dit combien de niveaux existent et
 * comment ils s'appellent.
 *
 * Le `path` matérialisé (« 1.7.23 ») permet de récupérer un sous-arbre entier
 * en une requête. C'est ce qui rendra l'agrégation de maîtrise praticable à
 * n'importe quelle profondeur, sans code conditionnel par concours.
 */
class CompetencyNode extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'exam_family_id', 'exam_id', 'parent_id', 'code',
        'name_fr', 'name_ar', 'description_fr', 'description_ar',
        'depth', 'path', 'position',
        'weight_percent', 'source_id', 'provenance',
    ];

    protected $hidden = ['id', 'exam_family_id', 'exam_id', 'source_id', 'parent_id'];

    public static function booted(): void
    {
        static::saving(function (self $node) {
            $node->assertNoCycle();
            $node->refreshHierarchy();
        });
    }

    /**
     * PAS-4.1 — Rattachement de référence : l'ÉPREUVE (ADR-0014).
     *
     * Chaque épreuve a sa propre matrice de domaines et de poids ; les
     * rattacher à la famille aurait forcé à fusionner trois matrices en une.
     */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /**
     * Rattachement historique, conservé volontairement.
     *
     * La migration 000230 ajoute `exam_id` sans retirer `exam_family_id` : les
     * deux colonnes coexistent. Un nœud rattaché à une famille sans épreuve
     * reste donc valide, et le catalogue du PAS-4 en produit — c'est ce que
     * vérifie `test_une_famille_peut_avoir_une_profondeur_differente`.
     * Supprimer cette relation casserait ces nœuds sans rien simplifier.
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(ExamFamily::class, 'exam_family_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /**
     * Tout le sous-arbre, en une requête, grâce au chemin matérialisé.
     *
     * Le cloisonnement se fait par ÉPREUVE depuis le PAS-4.1. On retombe sur
     * la famille pour les nœuds antérieurs, qui n'ont pas d'épreuve : filtrer
     * sur `exam_id` valant nul produirait `exam_id = NULL`, qui ne rapproche
     * aucune ligne en SQL et viderait silencieusement le sous-arbre.
     */
    public function scopeDescendantsOf(Builder $query, self $node): Builder
    {
        $query = $node->exam_id !== null
            ? $query->where('exam_id', $node->exam_id)
            : $query->where('exam_family_id', $node->exam_family_id);

        return $query->where('path', 'like', $node->path.'.%');
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id')->orderBy('position');
    }

    public function localized(string $field, ?string $locale = null): ?string
    {
        $locale = $locale ?? app()->getLocale();

        return $this->getAttribute($field.($locale === 'ar' ? '_ar' : '_fr'))
            ?: $this->getAttribute($field.'_fr');
    }

    /**
     * Nom du niveau auquel ce nœud appartient.
     *
     * Le profil de l'ÉPREUVE fait autorité : « Bloc » pour la didactique,
     * « Domaine » pour les sciences de l'éducation — deux épreuves, deux
     * vocabulaires. Repli sur le profil de la famille pour les nœuds sans
     * épreuve, sans quoi ils perdraient leur nom de niveau.
     */
    public function levelName(?string $locale = null): ?string
    {
        return $this->exam?->taxonomyProfile?->levelName($this->depth, $locale)
            ?? $this->family?->taxonomyProfile?->levelName($this->depth, $locale);
    }

    /**
     * La contrainte SQL empêche qu'un nœud soit son propre parent direct.
     * Le cycle plus long — A parent de B, puis B redevenu parent de A — ne se
     * détecte qu'en remontant la chaîne.
     */
    private function assertNoCycle(): void
    {
        if ($this->parent_id === null) {
            return;
        }

        if ($this->exists && (int) $this->parent_id === (int) $this->id) {
            throw new RuntimeException('Un nœud de compétence ne peut pas être son propre parent.');
        }

        $ancestor = self::find($this->parent_id);
        $seen = 0;

        while ($ancestor !== null) {
            if ($this->exists && (int) $ancestor->id === (int) $this->id) {
                throw new RuntimeException(
                    'Cycle détecté : ce nœud deviendrait son propre ancêtre.'
                );
            }

            if (++$seen > TaxonomyProfile::MAX_DEPTH) {
                throw new RuntimeException('Profondeur de taxonomie dépassée.');
            }

            $ancestor = $ancestor->parent_id ? self::find($ancestor->parent_id) : null;
        }
    }

    private function refreshHierarchy(): void
    {
        if ($this->parent_id === null) {
            $this->depth = 0;
            $this->path = null;   // complété après insertion, l'id n'existe pas encore

            return;
        }

        $parent = self::findOrFail($this->parent_id);

        $this->depth = $parent->depth + 1;
        $this->path = ($parent->path ?? (string) $parent->id);
    }

    /** Le chemin définitif ne peut s'écrire qu'une fois l'id connu. */
    protected static function boot(): void
    {
        parent::boot();

        static::created(function (self $node) {
            $node->path = $node->parent_id === null
                ? (string) $node->id
                : $node->path.'.'.$node->id;

            $node->saveQuietly();
        });
    }
}
