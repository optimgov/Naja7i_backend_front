<?php

namespace Tests\Feature\Auth;

use App\Models\LegalDocument;
use App\Models\LegalEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LegalConsentService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
        Notification::fake();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Amal',
            'last_name' => 'El Mansouri',
            'academic_level' => 'Licence',
            'address' => 'Rabat',
            'email' => 'candidat@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide',
            'password_confirmation' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'terms_accepted' => true,
            'privacy_notice_acknowledged' => true,
            'marketing_granted' => false,
        ], $overrides);
    }

    private function register(array $overrides = [])
    {
        return $this->postJson('/api/v1/auth/register', $this->payload($overrides));
    }

    // --- Inscription ------------------------------------------------------

    public function test_inscription_cree_compte_identite_role_et_trois_actes_juridiques(): void
    {
        $this->register()->assertCreated()
            ->assertJsonPath('data.email', 'candidat@naja7i.ma')
            ->assertJsonPath('data.first_name', null)
            ->assertJsonPath('data.academic_level', null)
            ->assertJsonPath('data.onboarding_complete', false);

        $user = User::where('email', 'candidat@naja7i.ma')->firstOrFail();

        $this->assertDatabaseHas('identities', ['user_id' => $user->id, 'provider' => 'password']);
        $this->assertTrue($user->hasRole('candidat'));

        // Trois actes distincts, y compris le refus marketing.
        $actions = LegalEvent::where('user_id', $user->id)->pluck('action')->all();
        $this->assertContains(LegalEvent::TERMS_ACCEPTED, $actions);
        $this->assertContains(LegalEvent::PRIVACY_ACKED, $actions);
        $this->assertContains(LegalEvent::MARKETING_WITHDRAWN, $actions);
    }

    public function test_inscription_refusee_sans_acceptation_des_cgu(): void
    {
        $this->register(['terms_accepted' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('terms_accepted');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_aucun_compte_partiel_si_une_etape_echoue(): void
    {
        $this->register(['locale' => 'es'])->assertStatus(422);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('identities', 0);
        $this->assertDatabaseCount('legal_events', 0);
    }

    public function test_email_deja_utilise_renvoie_409(): void
    {
        $this->register();
        $this->post('/api/v1/auth/logout');

        $this->register()
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'AUTH_EMAIL_ALREADY_USED');
    }

    public function test_l_email_est_insensible_a_la_casse(): void
    {
        $this->register();
        $this->post('/api/v1/auth/logout');

        $this->register(['email' => 'Candidat@Naja7i.MA'])->assertStatus(409);
    }

    // --- Politique de mot de passe ---------------------------------------

    public function test_mot_de_passe_trop_court_refuse(): void
    {
        $this->register([
            'password' => 'court-11car', 'password_confirmation' => 'court-11car',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_douze_caracteres_suffisent(): void
    {
        $this->register([
            'password' => 'douzecarac12', 'password_confirmation' => 'douzecarac12',
        ])->assertCreated();
    }

    public function test_une_phrase_avec_espaces_est_acceptee(): void
    {
        $phrase = 'le zellige bleu de sale';

        $this->register(['password' => $phrase, 'password_confirmation' => $phrase])
            ->assertCreated();
    }

    public function test_un_mot_de_passe_unicode_est_accepte(): void
    {
        $phrase = 'كلمة السر الطويلة جدا';

        $this->register(['password' => $phrase, 'password_confirmation' => $phrase])
            ->assertCreated();
    }

    // --- Connexion --------------------------------------------------------

    public function test_connexion_reussie_et_regeneration_de_session(): void
    {
        $this->register();
        $this->post('/api/v1/auth/logout');

        $avant = session()->getId();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'candidat@naja7i.ma', 'password' => 'une-phrase-de-passe-solide',
        ])->assertOk();

        $this->assertAuthenticated();
        $this->assertNotSame($avant, session()->getId(), 'La session doit être régénérée après connexion.');
    }

    public function test_reponses_identiques_que_le_compte_existe_ou_non(): void
    {
        $this->register();
        $this->post('/api/v1/auth/logout');

        $existant = $this->postJson('/api/v1/auth/login', [
            'email' => 'candidat@naja7i.ma', 'password' => 'mauvais-mot-de-passe',
        ]);
        $inexistant = $this->postJson('/api/v1/auth/login', [
            'email' => 'inconnu@naja7i.ma', 'password' => 'mauvais-mot-de-passe',
        ]);

        $existant->assertStatus(401)->assertJsonPath('error.code', 'AUTH_INVALID_CREDENTIALS');
        $inexistant->assertStatus(401)->assertJsonPath('error.code', 'AUTH_INVALID_CREDENTIALS');
        $this->assertSame($existant->json('error.message'), $inexistant->json('error.message'));
    }

    public function test_compte_suspendu_refuse(): void
    {
        $this->register();
        $this->post('/api/v1/auth/logout');
        User::where('email', 'candidat@naja7i.ma')->update(['status' => 'suspended']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'candidat@naja7i.ma', 'password' => 'une-phrase-de-passe-solide',
        ])->assertStatus(403)->assertJsonPath('error.code', 'AUTH_ACCOUNT_SUSPENDED');
    }

    public function test_la_limite_par_compte_resiste_au_changement_d_ip(): void
    {
        $this->register();
        $this->post('/api/v1/auth/logout');

        // Cinq échecs depuis cinq IP différentes : le compteur par e-mail doit
        // continuer de compter malgré la rotation d'adresses.
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])
                ->postJson('/api/v1/auth/login', [
                    'email' => 'candidat@naja7i.ma', 'password' => 'faux',
                ]);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'candidat@naja7i.ma', 'password' => 'faux',
            ])->assertStatus(429);
    }

    public function test_la_limite_par_ip_resiste_au_changement_de_compte(): void
    {
        // Pulvérisation : une IP, beaucoup d'adresses différentes.
        for ($i = 1; $i <= 30; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
                ->postJson('/api/v1/auth/login', [
                    'email' => "cible{$i}@naja7i.ma", 'password' => 'Motdepasse2026',
                ]);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson('/api/v1/auth/login', [
                'email' => 'cible99@naja7i.ma', 'password' => 'Motdepasse2026',
            ])->assertStatus(429);
    }

    // --- Session ----------------------------------------------------------

    public function test_me_inaccessible_sans_session(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_deconnexion_ferme_la_session(): void
    {
        $this->register();

        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest();
        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    // --- Actes juridiques version-aware (BLOC-4) --------------------------

    public function test_une_nouvelle_version_des_cgu_invalide_l_acceptation_precedente(): void
    {
        $this->register();
        $user = User::where('email', 'candidat@naja7i.ma')->firstOrFail();
        $legal = app(LegalConsentService::class);

        $this->assertTrue($legal->hasAcceptedCurrent($user, LegalDocument::KIND_TERMS, 'fr'));
        $this->assertSame([], $legal->pendingActions($user, 'fr'));

        // Publication d'une v2.
        LegalDocument::create([
            'kind' => LegalDocument::KIND_TERMS, 'version' => '2027-01-01', 'locale' => 'fr',
            'title' => 'CGU v2', 'summary' => 'Nouvelle version', 'checksum' => hash('sha256', 'v2'),
            'published_at' => now(),
        ]);

        // L'acceptation de la v1 ne satisfait plus rien.
        $this->assertFalse($legal->hasAcceptedCurrent($user, LegalDocument::KIND_TERMS, 'fr'));
        $this->assertContains(LegalDocument::KIND_TERMS, $legal->pendingActions($user, 'fr'));
    }

    public function test_le_retrait_marketing_conserve_l_octroi_anterieur(): void
    {
        $this->register(['marketing_granted' => true]);
        $user = User::where('email', 'candidat@naja7i.ma')->firstOrFail();

        $this->patchJson('/api/v1/me/legal/marketing', ['granted' => false])->assertOk();

        $actions = LegalEvent::where('user_id', $user->id)
            ->whereHas('document', fn ($q) => $q->where('kind', LegalDocument::KIND_MARKETING))
            ->orderBy('id')->pluck('action')->all();

        $this->assertSame(
            [LegalEvent::MARKETING_GRANTED, LegalEvent::MARKETING_WITHDRAWN],
            $actions,
            'L\'octroi antérieur doit rester dans l\'historique.'
        );
    }

    public function test_les_cgu_ne_sont_pas_revocables_par_l_api(): void
    {
        $this->register();

        $this->patchJson('/api/v1/me/legal/terms', ['granted' => false])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'LEGAL_ACT_NOT_REVOCABLE');
    }

    public function test_la_preuve_contient_document_version_et_empreinte(): void
    {
        $this->register();

        $this->getJson('/api/v1/me/legal')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['action', 'document_kind', 'document_version', 'checksum', 'occurred_at']],
            ]);
    }

    public function test_l_ip_est_tronquee_dans_la_preuve(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '196.200.145.77'])->register();

        $this->assertDatabaseHas('legal_events', ['ip_prefix' => '196.200.145.0']);
        $this->assertDatabaseMissing('legal_events', ['ip_prefix' => '196.200.145.77']);
    }

    public function test_les_documents_sont_publics_et_localises(): void
    {
        $this->getJson('/api/v1/legal/documents?locale=ar')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.locale', 'ar');
    }

    public function test_les_textes_provisoires_sont_signales_comme_tels(): void
    {
        $this->getJson('/api/v1/legal/documents')
            ->assertOk()
            ->assertJsonPath('data.0.provisional', true);
    }
}
