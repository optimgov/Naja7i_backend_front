<?php

namespace Tests\Feature\Profil;

use App\Models\Audience;
use App\Models\Exam;
use App\Models\ExamFamily;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La catégorie de public du candidat, sur son profil — M-017 pas 1.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI CE CHAMP EXISTE
 *
 * M-015 a livré la condition de public **des offres** : l'écran peut dire
 * « réservé aux candidats CRMEF ». Il ne pouvait pas encore **comparer**, faute
 * de savoir de quelle catégorie relève le candidat qui regarde — et le pas 5 de
 * M-009 en a besoin pour décider si le bouton « Choisir cette offre » se rend.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ELLE SE DÉDUIT, ELLE NE SE DÉCLARE PAS
 *
 * Épreuve → parcours → famille → catégorie. C'est la même chaîne que le refus
 * de souscription emprunte (3A.9 pas 3), et c'est ce qui garantit que l'écran
 * annonce exactement ce que le serveur opposera.
 *
 * **Sans épreuve déclarée, le champ n'existe pas** — jamais `null`, jamais
 * « tous ». *On ne refuse que ce qu'on sait* : une catégorie inventée servirait
 * à refuser une vente à quelqu'un qui paie.
 */
class CategorieDuCandidatTest extends TestCase
{
    use RefreshDatabase;

    private User $candidat;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());

        $this->candidat = User::create([
            'email' => 'categorie@naja7i.ma', 'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr',
        ]);
        $this->candidat->markEmailAsVerified();
        $this->candidat->grantCandidateRole();
        $this->candidat = $this->candidat->fresh();
    }

    /** @return array<string, mixed> */
    private function profil(): array
    {
        return $this->actingAs($this->candidat)
            ->getJson('/api/v1/me/profile')->assertOk()->json('data');
    }

    // ═══ Les deux cas ══════════════════════════════════════════════════════

    public function test_un_candidat_qui_a_declare_son_epreuve_rend_sa_categorie(): void
    {
        $crmef = Audience::where('code', 'crmef')->sole();

        $this->actingAs($this->candidat)
            ->putJson('/api/v1/me/profile', ['exam_code' => 'CRMEF-SE-2025'])
            ->assertOk();

        $profil = $this->profil();

        $this->assertSame('CRMEF-SE-2025', $profil['exam_code']);
        $this->assertSame('crmef', $profil['audience']['code']);
        $this->assertSame($crmef->name_fr, $profil['audience']['label_fr']);
        $this->assertSame($crmef->name_ar, $profil['audience']['label_ar']);
    }

    public function test_un_candidat_sans_epreuve_declaree_ne_rend_pas_le_champ(): void
    {
        $profil = $this->profil();

        $this->assertNull($profil['exam_code'], 'La déclaration absente se dit par `null`…');

        /* …mais la catégorie DÉDUITE, elle, disparaît. La distinction est le
         * cœur du pas : « je n'ai pas choisi » est une information ; « je suis
         * de catégorie inconnue » n'en est pas une, et rendre `null` inviterait
         * l'écran à traiter l'inconnu comme une catégorie. */
        $this->assertArrayNotHasKey('audience', $profil);
    }

    public function test_la_reponse_du_put_porte_la_categorie_sans_second_appel(): void
    {
        /* Le frontend écrit puis affiche : lui faire redemander le profil pour
         * connaître une catégorie qu'il vient de déterminer serait une friction
         * gratuite sur le geste le plus fréquent de l'écran de compte. */
        $donnees = $this->actingAs($this->candidat)
            ->putJson('/api/v1/me/profile', ['exam_code' => 'CRMEF-SE-2025'])
            ->assertOk()
            ->json('data');

        $this->assertSame('crmef', $donnees['audience']['code']);
    }

    // ═══ Ce que la forme garantit ══════════════════════════════════════════

    public function test_la_forme_est_exactement_celle_des_offres(): void
    {
        $this->actingAs($this->candidat)
            ->putJson('/api/v1/me/profile', ['exam_code' => 'CRMEF-SE-2025'])->assertOk();

        $duCandidat = $this->profil()['audience'];

        $deLOffre = collect($this->getJson('/api/v1/plans')->assertOk()->json('data'))
            ->firstWhere('code', 'preparation-30j')['audience'];

        /* MÊME FORME DES DEUX CÔTÉS. C'est ce qui rend la comparaison possible
         * en une ligne côté écran ; deux formes pour une même notion auraient
         * obligé à traduire l'une dans l'autre, et une traduction se trompe. */
        $this->assertSame(['code', 'label_fr', 'label_ar'], array_keys($duCandidat));
        $this->assertSame(array_keys($deLOffre), array_keys($duCandidat));
        $this->assertSame($deLOffre, $duCandidat, 'Même catégorie, mêmes valeurs.');
    }

    public function test_une_famille_sans_categorie_ne_fabrique_aucune_categorie(): void
    {
        /* Les familles en liste d'attente n'ont pas de public : personne ne l'a
         * décidé. Le profil d'un candidat qui en préparerait une ne doit pas
         * inventer la catégorie de la famille voisine. */
        $famille = ExamFamily::whereNull('audience_id')->firstOrFail();
        $epreuve = Exam::published()->whereHas('track', fn ($q) => $q
            ->where('exam_family_id', $famille->id))->first();

        if ($epreuve === null) {
            $epreuve = Exam::published()->firstOrFail();
            $epreuve->track->family->forceFill(['audience_id' => null])->save();
        }

        $this->actingAs($this->candidat)
            ->putJson('/api/v1/me/profile', ['exam_code' => $epreuve->code])->assertOk();

        $this->assertArrayNotHasKey('audience', $this->profil());
    }

    public function test_aucun_identifiant_interne_ne_sort_avec_la_categorie(): void
    {
        $this->actingAs($this->candidat)
            ->putJson('/api/v1/me/profile', ['exam_code' => 'CRMEF-SE-2025'])->assertOk();

        $aplati = json_encode($this->profil(), JSON_UNESCAPED_UNICODE);

        foreach (['audience_id', 'exam_id', 'user_id', 'tenant_id', '"id"'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $aplati);
        }
    }
}
