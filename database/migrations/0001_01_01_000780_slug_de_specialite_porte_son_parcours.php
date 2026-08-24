<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DET-101 — Une URL doit pouvoir désigner UNE spécialité.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUI ÉTAIT CASSÉ, ET COMMENT ON L'A VU
 *
 * Depuis `000220`, une spécialité pend du PARCOURS et l'unicité est
 * `(track_id, slug)` : « langue-francaise » existe donc deux fois sous CRMEF,
 * une fois par parcours. Mais la route publique adresse une spécialité par
 * `(famille, slug)` — un couple qui n'identifiait plus rien.
 *
 * Mesuré sur la préproduction le 24 août 2026 :
 * `GET catalogue/familles/crmef/specialites/langue-francaise` rendait
 * « Primaire bilingue / waitlist », tandis que « Secondaire collégial et
 * qualifiant / OPEN » — la seule spécialité ouverte du pilote — n'était
 * atteignable par aucune URL. Le candidat cliquait « ouvert » et lisait
 * « liste d'attente », sans bouton de diagnostic.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE CHOIX : LE SLUG PORTE SON PARCOURS
 *
 * Arbitrage du propriétaire, 24 août 2026. Trois voies existaient — un segment
 * de parcours dans l'URL, des slugs distincts au référentiel, ou un `->first()`
 * qui préfère l'ouverte. La deuxième a été retenue : elle ne change ni le
 * contrat d'API ni les écrans, et rouvre l'entonnoir sans lot frontend.
 *
 * LE SUFFIXE EST SYSTÉMATIQUE, pas réservé aux collisions. Ne suffixer que les
 * doublons rendrait le slug d'une spécialité dépendant de l'existence d'une
 * AUTRE : ajouter « philosophie » au primaire renommerait celle du secondaire,
 * et casserait son URL sans que personne n'y ait touché. Un suffixe partout
 * est plus laid et ne bouge jamais.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * L'UNICITÉ REVIENT — C'EST ELLE QUI TIENT LA PROMESSE
 *
 * `000220` avait retiré `(exam_family_id, slug)` avec une bonne raison : le
 * référentiel officiel était inchargeable sous cette contrainte. La raison
 * tombe dès que les slugs portent leur parcours, et la contrainte est ce qui
 * empêche le défaut de revenir — un test peut être oublié, un index non.
 *
 * Elle ne peut pas échouer ici : `tracks` est unique sur
 * `(exam_family_id, slug)`, donc deux parcours d'une même famille donnent deux
 * suffixes distincts, et un même parcours ne porte jamais deux fois la même
 * discipline (`(track_id, slug)`, toujours en place).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Le garde `NOT LIKE` rend le renommage rejouable. Il n'y a pas de
         * seconde exécution d'une migration, mais une base à moitié amorçée à
         * la main — le cas de la préproduction — mérite qu'on ne double pas le
         * suffixe si quelqu'un est passé avant.
         */
        DB::statement(<<<'SQL'
            UPDATE specialties AS s
               SET slug = s.slug || '-' || t.slug,
                   updated_at = now()
              FROM tracks AS t
             WHERE s.track_id = t.id
               AND s.slug NOT LIKE '%-' || t.slug
        SQL);

        Schema::table('specialties', function (Blueprint $table) {
            $table->unique(['exam_family_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('specialties', function (Blueprint $table) {
            $table->dropUnique('specialties_exam_family_id_slug_unique');
        });

        DB::statement(<<<'SQL'
            UPDATE specialties AS s
               SET slug = left(s.slug, length(s.slug) - length(t.slug) - 1),
                   updated_at = now()
              FROM tracks AS t
             WHERE s.track_id = t.id
               AND s.slug LIKE '%-' || t.slug
        SQL);
    }
};
