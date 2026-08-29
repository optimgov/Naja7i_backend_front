<?php

namespace Tests\Feature\Profil;

use App\Models\Identity;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\NiveauxAcademiques;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LE LYCÉEN N'EST PLUS FORCÉ À DÉCLARER UN CONCOURS.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE DÉFAUT QUE CES TESTS FERMENT
 *
 * `academic_level` était une saisie libre, et le dossier n'était réputé
 * complet qu'une fois une épreuve déclarée. Or le catalogue ne sert que les
 * familles ouvertes — le lycée est en liste d'attente. Un élève de tronc
 * commun n'avait donc, dans sa liste déroulante, que les trois épreuves du
 * CRMEF : pour débloquer son compte, il devait se déclarer candidat à un
 * concours d'enseignement. Observé en préproduction sur un compte réel, qui
 * s'était vu attribuer « Spécialité — Langue française ».
 *
 * Deux choses le corrigent, et les deux sont testées ici :
 *  – le niveau devient une LISTE FERMÉE, servie par le serveur, donc la
 *    plateforme sait enfin reconnaître un lycéen ;
 *  – un lycéen a un dossier complet SANS épreuve.
 */
final class NiveauAcademiqueTest extends TestCase
{
    use RefreshDatabase;

    private int $telephones = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
    }

    // ── La liste servie ─────────────────────────────────────────────────

    /**
     * ELLE EST PUBLIQUE, parce que l'inscription devra la lire avant qu'un
     * jeton existe.
     */
    public function test_la_liste_des_niveaux_se_lit_sans_authentification(): void
    {
        $reponse = $this->getJson('/api/v1/catalogue/niveaux-academiques')->assertOk();

        $this->assertSame(
            NiveauxAcademiques::tous(),
            array_column($reponse->json('data'), 'code'),
            'La route ne sert pas exactement la liste fermée, dans son ordre.'
        );
    }

    /**
     * CHAQUE NIVEAU EST NOMMÉ, dans les deux langues. Un code nu dans une liste
     * déroulante — « deuxieme-bac » — n'est pas un libellé.
     */
    public function test_chaque_niveau_porte_un_libelle_dans_les_deux_langues(): void
    {
        foreach (['fr', 'ar'] as $langue) {
            $niveaux = $this->withHeader('Accept-Language', $langue)
                ->getJson('/api/v1/catalogue/niveaux-academiques')
                ->assertOk()
                ->json('data');

            foreach ($niveaux as $niveau) {
                $this->assertNotSame(
                    "dossier.niveau_{$niveau['code']}",
                    $niveau['name'],
                    "Le niveau {$niveau['code']} n’a pas de libellé en {$langue}."
                );
                $this->assertNotSame('', trim($niveau['name']));
            }
        }
    }

    /**
     * L'ARABE EST BIEN SERVI, pas seulement le français absent : sans cette
     * moitié, une clé recopiée du français passerait le test précédent.
     */
    public function test_un_arabophone_lit_ses_niveaux_en_arabe(): void
    {
        $fr = $this->withHeader('Accept-Language', 'fr')
            ->getJson('/api/v1/catalogue/niveaux-academiques')->json('data');
        $ar = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/catalogue/niveaux-academiques')->json('data');

        foreach (array_map(null, $fr, $ar) as [$enFrancais, $enArabe]) {
            $this->assertNotSame($enFrancais['name'], $enArabe['name'], "« {$enFrancais['name']} » n’est pas traduit.");
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $enArabe['name']);
        }
    }

    /** Le drapeau qui permet au dossier d'orienter l'élève sans redéduire la règle. */
    public function test_la_liste_dit_lesquels_sont_des_niveaux_de_lycee(): void
    {
        $niveaux = collect($this->getJson('/api/v1/catalogue/niveaux-academiques')->json('data'));

        $this->assertSame(
            NiveauxAcademiques::LYCEE,
            $niveaux->where('lycee', true)->pluck('code')->values()->all()
        );
    }

    // ── La validation ───────────────────────────────────────────────────

    /** Une valeur hors liste est refusée : c'est ce qui rend le drapeau fiable. */
    public function test_un_niveau_hors_liste_est_refuse(): void
    {
        $candidat = $this->candidat('saisie-libre@naja7i.ma');

        $this->actingAs($candidat)
            ->patchJson('/api/v1/me/account', ['academic_level' => 'tronc commun'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('academic_level');

        $this->assertNull($candidat->fresh()->academic_level);
    }

    /**
     * LE REFUS SE LIT. Sans nom d'attribut traduit, le message affiché sous le
     * champ était « La valeur sélectionnée pour academic level est invalide » —
     * une colonne de base de données montrée à un lycéen.
     */
    public function test_le_refus_nomme_le_champ_dans_la_langue_du_compte(): void
    {
        $candidat = $this->candidat('message-lisible@naja7i.ma');

        $message = $this->actingAs($candidat)
            ->patchJson('/api/v1/me/account', ['academic_level' => 'TC'])
            ->assertUnprocessable()
            ->json('error.details.0.messages.0');

        $this->assertStringNotContainsString('academic level', $message);
        $this->assertStringContainsString('niveau académique', $message);
    }

    public function test_un_niveau_de_la_liste_est_accepte(): void
    {
        $candidat = $this->candidat('lyceen@naja7i.ma');

        $this->actingAs($candidat)
            ->patchJson('/api/v1/me/account', ['academic_level' => 'tronc-commun'])
            ->assertOk()
            ->assertJsonPath('data.est_lyceen', true);
    }

    // ── Le dossier ──────────────────────────────────────────────────────

    /**
     * LE CŒUR DU CORRECTIF. Sans épreuve déclarée, et parce qu'il est lycéen,
     * son dossier est complet — la navigation s'ouvre.
     */
    public function test_le_dossier_d_un_lyceen_est_complet_sans_epreuve(): void
    {
        foreach (NiveauxAcademiques::LYCEE as $niveau) {
            $candidat = $this->candidat("dossier-{$niveau}@naja7i.ma");
            $this->identifie($candidat, $niveau);

            $this->assertTrue(
                $candidat->fresh()->dossierCandidatComplet(),
                "Un élève de « {$niveau} » reste bloqué sans concours."
            );
        }
    }

    /** Et la règle ne déborde pas : hors lycée, l'épreuve reste exigée. */
    public function test_le_dossier_d_un_post_bac_exige_toujours_une_epreuve(): void
    {
        $candidat = $this->candidat('post-bac@naja7i.ma');
        $this->identifie($candidat, 'licence');

        $this->assertFalse($candidat->fresh()->dossierCandidatComplet());
    }

    /**
     * ET LA LEVÉE NE PORTE QUE SUR L'ÉPREUVE : l'identité reste exigée d'un
     * lycéen comme de tout le monde. Sans ce test, un `return true` posé trop
     * haut aurait ouvert la navigation à un dossier vide, et le premier test
     * serait resté vert.
     */
    public function test_le_lyceen_doit_toujours_donner_son_identite(): void
    {
        $candidat = $this->candidat('lyceen-anonyme@naja7i.ma');
        $candidat->forceFill(['academic_level' => 'tronc-commun'])->save();

        $this->assertFalse($candidat->fresh()->dossierCandidatComplet());
    }

    /** L'identité complète, sans aucune épreuve déclarée. Le téléphone est unique en base. */
    private function identifie(User $candidat, string $niveau): void
    {
        $this->telephones++;

        $candidat->forceFill([
            'first_name' => 'Salma',
            'last_name' => 'Bennani',
            'academic_level' => $niveau,
            'address' => '12 rue des Écoles, Rabat',
            'phone' => sprintf('+2126000000%02d', $this->telephones),
        ])->save();
    }

    private function candidat(string $email): User
    {
        $user = User::create([
            'email' => $email,
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        Identity::create(['user_id' => $user->id, 'provider' => 'password']);
        $user->memberships()->create([
            'role_id' => Role::where('code', 'candidat')->whereNull('tenant_id')->value('id'),
        ]);

        return $user->fresh();
    }
}
