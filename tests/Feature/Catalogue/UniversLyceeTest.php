<?php

namespace Tests\Feature\Catalogue;

use App\Models\Exam;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-0038 — le second univers est en place, et il est SÉPARÉ du premier.
 *
 * Ce que ces tests défendent n'est pas le nombre de lignes : c'est la
 * SÉPARATION décidée le 26 août. Deux mondes, deux arbres, aucune fuite de
 * l'un vers l'autre. Un test qui compterait seulement onze épreuves resterait
 * vert le jour où une matière de lycée se rattacherait au CRMEF.
 */
class UniversLyceeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
    }

    /** Les onze matières attendues, par leur code d'épreuve. */
    private const CODES = [
        'TCS-MATH', 'TCS-PC', 'TCS-SVT',
        '1BAC-SE-MATH', '1BAC-SE-PC', '1BAC-SE-SVT',
        '1BAC-SM-MATH',
        '2BAC-PC-MATH', '2BAC-PC-PC',
        '2BAC-SVT-SVT',
        '2BAC-SM-MATH',
    ];

    public function test_l_univers_lycee_porte_ses_onze_matieres_sur_trois_niveaux(): void
    {
        $filiere = DB::table('filieres')->where('slug', 'lycee')->first();
        $this->assertNotNull($filiere, 'La filière `lycee` doit exister.');

        $niveaux = DB::table('exam_families')->where('filiere_id', $filiere->id)->pluck('id');
        $this->assertCount(3, $niveaux, 'Trois niveaux : tronc commun, 1re Bac, 2e Bac.');

        $parcours = DB::table('tracks')->whereIn('exam_family_id', $niveaux)->pluck('id');
        $this->assertCount(6, $parcours);

        $this->assertSame(
            self::CODES,
            Exam::whereIn('track_id', $parcours)->orderByRaw('array_position(?::text[], code)', [
                '{'.implode(',', self::CODES).'}',
            ])->pluck('code')->all(),
            'Les onze épreuves du lycée, et elles seules.'
        );
    }

    /**
     * LE TEST QUI DISCRIMINE — la séparation des deux mondes.
     *
     * Sans lui, rattacher une matière de lycée au CRMEF passerait inaperçu :
     * le compte de onze resterait juste, et la carte de maîtrise d'un lycéen
     * commencerait à compter vers une préparation de professeur.
     */
    public function test_aucune_epreuve_de_lycee_ne_pend_du_crmef(): void
    {
        $filiereCrmef = DB::table('filieres')->where('slug', 'sciences-education')->value('id');
        $famillesCrmef = DB::table('exam_families')->where('filiere_id', $filiereCrmef)->pluck('id');
        $parcoursCrmef = DB::table('tracks')->whereIn('exam_family_id', $famillesCrmef)->pluck('id');

        $intruses = Exam::whereIn('code', self::CODES)->whereIn('track_id', $parcoursCrmef)->pluck('code');

        $this->assertEmpty($intruses, 'Une matière de lycée rattachée au CRMEF : '.$intruses->implode(', '));
    }

    /**
     * RIEN N'EST OUVERT, ET C'EST VOULU.
     *
     * Aucune de ces onze épreuves ne porte encore une question. Les publier
     * offrirait au candidat une porte qui montre sans ouvrir — ce que la règle
     * des portes proscrit. L'expert ouvrira matière par matière.
     */
    public function test_rien_du_lycee_n_est_publie_tant_qu_aucune_question_n_existe(): void
    {
        $publiees = Exam::whereIn('code', self::CODES)->where('status', 'published')->pluck('code');

        $this->assertEmpty($publiees, 'Épreuve de lycée publiée sans banque : '.$publiees->implode(', '));

        $filiere = DB::table('filieres')->where('slug', 'lycee')->first();
        $this->assertSame('waitlist', $filiere->availability);
        $this->assertSame('draft', $filiere->status);
    }

    /**
     * LE COEFFICIENT RESTE NUL, ET CE N'EST PAS UN OUBLI.
     *
     * Les coefficients officiels du baccalauréat existent et sont publics,
     * mais aucun document du dépôt ne les atteste. Les inscrire referait
     * DET-60 : des poids rapportés dont personne n'a vu la pièce.
     */
    public function test_aucun_coefficient_n_est_invente(): void
    {
        $inventes = Exam::whereIn('code', self::CODES)->whereNotNull('coefficient')->pluck('code');

        $this->assertEmpty($inventes, 'Coefficient posé sans source : '.$inventes->implode(', '));

        $this->assertSame(
            ['unverified'],
            Exam::whereIn('code', self::CODES)->pluck('provenance')->unique()->values()->all()
        );
    }

    /**
     * LE SLUG DE SPÉCIALITÉ PORTE SON PARCOURS — règle de `000780`.
     *
     * « Mathématiques » existe six fois dans cet univers. Sans le suffixe,
     * l'unicité `(exam_family_id, slug)` refuserait la seconde, et la route de
     * spécialité rendrait la mauvaise ligne — c'est très exactement DET-101.
     */
    public function test_les_matieres_homonymes_ne_se_confondent_pas(): void
    {
        $filiere = DB::table('filieres')->where('slug', 'lycee')->value('id');
        $niveaux = DB::table('exam_families')->where('filiere_id', $filiere)->pluck('id');

        $maths = DB::table('specialties')
            ->whereIn('exam_family_id', $niveaux)
            ->where('name_fr', 'Mathématiques')
            ->pluck('slug');

        /*
         * PAS DE COMPTE EN DUR ICI. Le catalogue va grossir — il manque encore
         * les maths et la physique-chimie de la 2e Bac SVT, entre autres — et
         * un test qui fixerait le nombre rougirait à chaque ajout légitime
         * sans rien prouver de plus. Ce qui se défend, c'est l'UNICITÉ.
         */
        $this->assertGreaterThan(1, $maths->count(), 'Le cas n’est discriminant qu’à partir de deux homonymes.');
        $this->assertCount($maths->count(), $maths->unique(), 'Deux slugs identiques : '.$maths->implode(', '));
    }
}
