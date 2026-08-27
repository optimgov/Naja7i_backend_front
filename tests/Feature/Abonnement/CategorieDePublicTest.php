<?php

namespace Tests\Feature\Abonnement;

use App\Contracts\AccessGrant;
use App\Models\AccessGrantRecord;
use App\Models\Audience;
use App\Models\CompetencyNode;
use App\Models\Exam;
use App\Models\ExamFamily;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La catégorie de public — l'objet que la portée `audience` désignait sans lui.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CE FICHIER FERME
 *
 * DET-87 : « la portée `audience` est fermée en code mais le catalogue ne
 * possède pas d'objet `Audience` ni de rattachement parcourable depuis une
 * famille ». Tant que c'était vrai, un droit d'audience ne couvrait que
 * lui-même — il ne pouvait ouvrir aucune épreuve. Le scénario S-11 exige
 * l'inverse : « un droit `(audience, lycee)` couvre toute épreuve dont la
 * famille est rattachée à cette catégorie, à toute profondeur » et « il ne
 * couvre rien du CRMEF ».
 */
class CategorieDePublicTest extends TestCase
{
    use RefreshDatabase;

    private User $candidat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->candidat = User::create([
            'email' => 'public-cible@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
        ]);
    }

    // ═══ Le semis, et ce qu'il rattache ════════════════════════════════════

    public function test_le_semis_pose_crmef_et_y_rattache_la_famille_et_les_offres(): void
    {
        $crmef = Audience::where('code', 'crmef')->sole();

        $this->assertSame('المراكز الجهوية لمهن التربية والتكوين', $crmef->name_ar);
        $this->assertTrue($crmef->active);
        $this->assertSame($crmef->id, ExamFamily::where('slug', 'crmef')->value('audience_id'));
        $this->assertSame(
            [],
            Plan::whereNull('audience_id')->pluck('code')->all(),
            'Les offres présentes à la migration se rattachent à CRMEF.',
        );
    }

    public function test_une_famille_en_liste_d_attente_n_est_rattachee_a_personne(): void
    {
        $this->assertNull(
            ExamFamily::where('slug', 'agregation')->value('audience_id'),
            'Personne n’a décidé du public de cette famille : lui en inventer un serait '
            .'une donnée fausse dans un chemin d’autorisation.',
        );
    }

    // ═══ L'ascendance, qui est tout l'objet de DET-87 ══════════════════════

    public function test_un_droit_d_audience_couvre_les_epreuves_de_ses_familles(): void
    {
        $crmef = Audience::where('code', 'crmef')->sole();
        $epreuve = Exam::whereHas('competencyNodes')->firstOrFail();
        $noeud = CompetencyNode::where('exam_id', $epreuve->id)->firstOrFail();

        $this->octroyer('mastery.detail', AccessGrantRecord::SCOPE_AUDIENCE, $crmef->uuid);

        $this->assertTrue($this->couvre('mastery.detail', AccessGrantRecord::SCOPE_EXAM, $epreuve->uuid));
        $this->assertTrue($this->couvre(
            'mastery.detail', AccessGrantRecord::SCOPE_COMPETENCY_NODE, $noeud->uuid,
        ));
        $this->assertTrue($this->couvre(
            'mastery.detail',
            AccessGrantRecord::SCOPE_EXAM_FAMILY,
            ExamFamily::where('slug', 'crmef')->value('uuid'),
        ));
    }

    public function test_un_droit_d_audience_ne_couvre_rien_d_une_autre_categorie(): void
    {
        $lycee = Audience::create([
            'code' => 'public-de-test', 'name_fr' => 'Public de test', 'name_ar' => 'جمهور اختباري', 'position' => 20,
        ]);
        $epreuveCrmef = Exam::whereHas('competencyNodes')->firstOrFail();

        $this->octroyer('mastery.detail', AccessGrantRecord::SCOPE_AUDIENCE, $lycee->uuid);

        $this->assertFalse($this->couvre(
            'mastery.detail', AccessGrantRecord::SCOPE_EXAM, $epreuveCrmef->uuid,
        ));
    }

    public function test_une_famille_sans_categorie_n_est_couverte_par_aucune(): void
    {
        $crmef = Audience::where('code', 'crmef')->sole();
        $orpheline = ExamFamily::where('slug', 'agregation')->firstOrFail();

        $this->octroyer('mastery.detail', AccessGrantRecord::SCOPE_AUDIENCE, $crmef->uuid);

        $this->assertFalse($this->couvre(
            'mastery.detail', AccessGrantRecord::SCOPE_EXAM_FAMILY, $orpheline->uuid,
        ));
    }

    public function test_rattacher_une_famille_suffit_a_la_couvrir(): void
    {
        $lycee = Audience::create([
            'code' => 'public-de-test', 'name_fr' => 'Public de test', 'name_ar' => 'جمهور اختباري', 'position' => 20,
        ]);
        $famille = ExamFamily::where('slug', 'agregation')->firstOrFail();

        $this->octroyer('questions.answer', AccessGrantRecord::SCOPE_AUDIENCE, $lycee->uuid);
        $this->assertFalse($this->couvre(
            'questions.answer', AccessGrantRecord::SCOPE_EXAM_FAMILY, $famille->uuid,
        ));

        $famille->update(['audience_id' => $lycee->id]);

        $this->assertTrue(
            $this->couvre('questions.answer', AccessGrantRecord::SCOPE_EXAM_FAMILY, $famille->uuid),
            'C’est le rattachement, et lui seul, qui rend une famille couverte.',
        );
    }

    // ═══ Le public éligible est contractuel (Q-19) ═════════════════════════

    public function test_changer_la_categorie_de_public_versionne(): void
    {
        $lycee = Audience::create([
            'code' => 'public-de-test', 'name_fr' => 'Public de test', 'name_ar' => 'جمهور اختباري', 'position' => 20,
        ]);
        $offre = Plan::create([
            'code' => 'public-versionne',
            'audience_id' => Audience::where('code', 'crmef')->value('id'),
            'name_fr' => 'Offre', 'name_ar' => 'عرض',
            'price_cents' => 20000, 'currency' => 'MAD', 'duration_days' => 30,
            'capabilities' => [AccessGrant::QUESTIONS_ANSWER],
            'active' => true, 'position' => 1,
        ]);
        $premiere = $offre->currentVersion()->firstOrFail();

        $offre->update(['audience_id' => $lycee->id]);

        $seconde = $offre->fresh()->currentVersion()->firstOrFail();
        $this->assertNotSame($premiere->id, $seconde->id);
        $this->assertSame($lycee->id, $seconde->audience_id);
        $this->assertSame(
            Audience::where('code', 'crmef')->value('id'),
            $premiere->fresh()->audience_id,
            'La version vendue garde le public sous lequel elle a été vendue.',
        );
    }

    public function test_le_rang_d_affichage_d_une_categorie_ne_versionne_pas(): void
    {
        $offre = Plan::whereNotNull('audience_id')->firstOrFail();
        $avant = $offre->versions()->count();

        Audience::where('code', 'crmef')->sole()->update(['position' => 99]);

        $this->assertSame($avant, $offre->fresh()->versions()->count());
    }

    // ═══ Les deux interdits, tenus en base ═════════════════════════════════

    public function test_une_categorie_ne_se_supprime_jamais(): void
    {
        $this->expectException(QueryException::class);

        DB::table('audiences')->where('code', 'crmef')->delete();
    }

    public function test_le_code_d_une_categorie_est_fige(): void
    {
        $this->expectException(QueryException::class);

        DB::table('audiences')->where('code', 'crmef')->update(['code' => 'crmef-2']);
    }

    public function test_le_libelle_d_une_categorie_reste_modifiable(): void
    {
        $crmef = Audience::where('code', 'crmef')->sole();

        $crmef->update(['name_fr' => 'CRMEF — concours d’accès']);

        $this->assertSame('CRMEF — concours d’accès', $crmef->fresh()->name_fr);
    }

    private function couvre(string $capacite, string $type, string $uuid): bool
    {
        return app(AccessGrant::class)->allows($this->candidat, $capacite, $type, $uuid);
    }

    private function octroyer(string $capacite, string $type, string $uuid): void
    {
        AccessGrantRecord::create([
            'user_id' => $this->candidat->id,
            'capability' => $capacite,
            'scope_type' => $type,
            'scope_uuid' => $uuid,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'origin' => 'support',
            'origin_reference' => (string) Str::uuid7(),
        ]);
    }
}
