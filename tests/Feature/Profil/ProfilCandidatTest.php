<?php

namespace Tests\Feature\Profil;

use App\Models\CandidateProfile;
use App\Models\Exam;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le profil candidat — DET-42.
 *
 * CE QUI SE JOUE : l'épreuve préparée se DÉCLARE au lieu de se déduire. La
 * déduction par la tentative la plus récente se trompe dans un cas ordinaire, et
 * le produit ne savait pas la corriger — il n'avait aucun endroit où écrire la
 * réponse.
 *
 * DEUX SENS À CHAQUE FOIS, comme la note de méthode de l'audit tournée 2 le
 * demande. « Un profil absent n'est pas une erreur » se teste avec « un profil
 * présent rend bien ses valeurs » ; « une épreuve non publiée est refusée » avec
 * « une épreuve publiée est acceptée ».
 */
class ProfilCandidatTest extends TestCase
{
    use RefreshDatabase;

    private Exam $epreuve;

    private User $candidat;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->epreuve = Exam::where('code', 'CRMEF-SE-2025')->firstOrFail();
        $this->candidat = $this->candidat('candidat@naja7i.ma');
    }

    private function candidat(string $email): User
    {
        $user = User::create([
            'email' => $email, 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $user->markEmailAsVerified();
        $user->grantCandidateRole();

        return $user;
    }

    /** Bascule vers un autre candidat depuis une session vierge (cf. ParcoursHttpTest). */
    private function agirComme(User $user): self
    {
        $this->flushSession();

        return $this->actingAs($user);
    }

    // --- Un profil absent n'est pas une erreur ---------------------------------

    public function test_un_profil_absent_rend_des_champs_nuls_et_non_une_404(): void
    {
        $reponse = $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/profile')
            ->assertOk();

        $reponse->assertExactJson(['data' => [
            'exam_code' => null,
            'objective' => null,
            'target_date' => null,
            'updated_at' => null,
        ]]);
    }

    /**
     * LA FORME NE CHANGE PAS SELON QU'IL Y A UN PROFIL OU NON.
     *
     * Sans cette garantie, le frontend devrait écrire deux lectures pour un même
     * écran — et c'est le chemin « pas encore de profil » qu'il testerait le
     * moins, alors que c'est celui de tout compte neuf.
     */
    public function test_les_memes_cles_sont_rendues_avec_et_sans_profil(): void
    {
        $vide = $this->actingAs($this->candidat)->getJson('/api/v1/me/profile')->json('data');

        $this->actingAs($this->candidat)->putJson('/api/v1/me/profile', [
            'exam_code' => $this->epreuve->code,
        ])->assertOk();

        $rempli = $this->actingAs($this->candidat)->getJson('/api/v1/me/profile')->json('data');

        $this->assertSame(array_keys($vide), array_keys($rempli));
    }

    // --- La déclaration ---------------------------------------------------------

    public function test_le_candidat_declare_l_epreuve_qu_il_prepare(): void
    {
        $this->actingAs($this->candidat)
            ->putJson('/api/v1/me/profile', [
                'exam_code' => $this->epreuve->code,
                'objective' => 'Intégrer le CRMEF de Rabat',
                'target_date' => '2026-06-15',
            ])
            ->assertOk()
            ->assertJsonPath('data.exam_code', $this->epreuve->code)
            ->assertJsonPath('data.objective', 'Intégrer le CRMEF de Rabat')
            ->assertJsonPath('data.target_date', '2026-06-15');

        $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/profile')
            ->assertJsonPath('data.exam_code', $this->epreuve->code);
    }

    public function test_les_champs_du_plan_sont_optionnels(): void
    {
        $this->actingAs($this->candidat)
            ->putJson('/api/v1/me/profile', ['exam_code' => $this->epreuve->code])
            ->assertOk()
            ->assertJsonPath('data.objective', null)
            ->assertJsonPath('data.target_date', null);
    }

    /**
     * REJOUABLE SANS EFFET DE BORD.
     *
     * Le même appel deux fois doit rendre le même état — et une seule ligne en
     * base. Sans l'index d'unicité, deux déclarations concurrentes créeraient
     * deux profils, et « quelle épreuve je prépare » aurait de nouveau deux
     * réponses : exactement le défaut que ce pas ferme.
     */
    public function test_le_put_rejoue_donne_le_meme_etat(): void
    {
        $charge = [
            'exam_code' => $this->epreuve->code,
            'objective' => 'Objectif stable',
            'target_date' => '2026-06-15',
        ];

        $premier = $this->actingAs($this->candidat)
            ->putJson('/api/v1/me/profile', $charge)->assertOk()->json('data');

        $second = $this->actingAs($this->candidat)
            ->putJson('/api/v1/me/profile', $charge)->assertOk()->json('data');

        $this->assertSame($premier, $second);
        $this->assertSame(1, CandidateProfile::where('user_id', $this->candidat->id)->count());
    }

    public function test_redeclarer_remplace_l_epreuve_preparee(): void
    {
        $autre = Exam::published()->where('code', '<>', $this->epreuve->code)->firstOrFail();

        $this->actingAs($this->candidat)
            ->putJson('/api/v1/me/profile', ['exam_code' => $this->epreuve->code])->assertOk();

        $this->actingAs($this->candidat)
            ->putJson('/api/v1/me/profile', ['exam_code' => $autre->code])
            ->assertOk()
            ->assertJsonPath('data.exam_code', $autre->code);

        $this->assertSame(1, CandidateProfile::where('user_id', $this->candidat->id)->count());
    }

    // --- L'épreuve doit être publiée -------------------------------------------

    public function test_une_epreuve_non_publiee_est_refusee(): void
    {
        $brouillon = Exam::create([
            'track_id' => $this->epreuve->track_id,
            'code' => 'CRMEF-BROUILLON-2027',
            'name_fr' => 'Épreuve non publiée',
            'name_ar' => 'اختبار غير منشور',
            'coefficient' => 1,
            'duration_minutes' => 60,
            'format' => 'qcm',
            'status' => 'draft',
        ]);

        $this->actingAs($this->candidat)
            ->putJson('/api/v1/me/profile', ['exam_code' => $brouillon->code])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors('exam_code');

        $this->assertNull(
            $this->candidat->candidateProfile()->first(),
            'Un refus n\'écrit rien : pas de profil à moitié posé.'
        );

        /* L'autre sens : la même route accepte une épreuve publiée. Sans cette
         * moitié, un refus systématique passerait le test précédent. */
        $this->actingAs($this->candidat)
            ->putJson('/api/v1/me/profile', ['exam_code' => $this->epreuve->code])
            ->assertOk();
    }

    public function test_un_code_d_epreuve_inconnu_est_refuse_comme_un_non_publie(): void
    {
        $reponse = $this->actingAs($this->candidat)
            ->putJson('/api/v1/me/profile', ['exam_code' => 'CRMEF-INEXISTANT'])
            ->assertStatus(422);

        /* Message VOLONTAIREMENT AMBIGU, comme au PAS-4 : « n'existe pas ou
         * n'est pas encore publiée ». Distinguer les deux laisserait deviner le
         * catalogue à venir. */
        $this->assertSame(
            __('errors.not_found'),
            $reponse->json('errors.exam_code.0'),
        );
    }

    // --- Le profil d'autrui -----------------------------------------------------

    public function test_le_profil_d_un_autre_candidat_est_introuvable(): void
    {
        $autre = $this->candidat('autre@naja7i.ma');

        $this->actingAs($autre)->putJson('/api/v1/me/profile', [
            'exam_code' => $this->epreuve->code,
            'objective' => 'Le secret de quelqu\'un d\'autre',
        ])->assertOk();

        $vu = $this->agirComme($this->candidat)
            ->getJson('/api/v1/me/profile')
            ->assertOk()
            ->json('data');

        $this->assertNull($vu['exam_code'], 'Le profil du voisin n\'est pas le mien.');
        $this->assertNull($vu['objective']);
    }

    public function test_un_candidat_ne_peut_pas_ecrire_dans_le_profil_d_autrui(): void
    {
        $autre = $this->candidat('cible@naja7i.ma');

        /* `user_id` est hors de `$fillable` : la charge utile ne peut pas
         * désigner sa victime. Le profil écrit est celui de l'appelant. */
        $this->actingAs($this->candidat)->putJson('/api/v1/me/profile', [
            'exam_code' => $this->epreuve->code,
            'user_id' => $autre->id,
        ])->assertOk();

        $this->assertNull($autre->candidateProfile()->first());
        $this->assertNotNull($this->candidat->candidateProfile()->first());
    }

    // --- Portée tenant -----------------------------------------------------------

    /**
     * UN PROFIL PAR TENANT, et ce n'est pas une subtilité gratuite : le même
     * compte peut préparer une épreuve en B2C et une autre dans un centre
     * partenaire. Le profil posé sous un tenant est invisible sous l'autre.
     */
    public function test_le_profil_est_porte_par_le_tenant(): void
    {
        $this->actingAs($this->candidat)
            ->putJson('/api/v1/me/profile', ['exam_code' => $this->epreuve->code])
            ->assertOk();

        $centre = Tenant::create(['slug' => 'centre-fes', 'name' => 'Centre de Fès']);
        app(TenantContext::class)->set($centre);

        $this->assertNull(
            $this->candidat->candidateProfile()->first(),
            'Sous un autre tenant, le profil plateforme n\'existe pas.'
        );
    }

    // --- Ce qui sort, et rien d'autre --------------------------------------------

    public function test_la_ressource_est_une_liste_blanche_stricte(): void
    {
        $this->actingAs($this->candidat)->putJson('/api/v1/me/profile', [
            'exam_code' => $this->epreuve->code,
            'objective' => 'Un objectif',
            'target_date' => '2026-06-15',
        ])->assertOk();

        $data = $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/profile')->json('data');

        $this->assertSame(
            ['exam_code', 'objective', 'target_date', 'updated_at'],
            array_keys($data),
            'Un champ ajouté demain au modèle ne doit pas apparaître par accident.'
        );

        foreach (['id', 'uuid', 'tenant_id', 'user_id', 'exam_id'] as $interne) {
            $this->assertArrayNotHasKey($interne, $data);
        }
    }

    public function test_la_route_exige_une_session_et_un_email_verifie(): void
    {
        $this->getJson('/api/v1/me/profile')->assertUnauthorized();
        $this->putJson('/api/v1/me/profile', ['exam_code' => $this->epreuve->code])->assertUnauthorized();

        $nonVerifie = User::create([
            'email' => 'non-verifie@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $nonVerifie->grantCandidateRole();

        $this->agirComme($nonVerifie)->getJson('/api/v1/me/profile')->assertForbidden();
    }
}
