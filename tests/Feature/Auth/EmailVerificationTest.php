<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Models\VerificationToken;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\EmailVerificationService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
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

    private function registerAndCaptureToken(array $overrides = []): string
    {
        $this->postJson('/api/v1/auth/register', $this->payload($overrides))->assertCreated();

        $user = User::where('email', $this->payload($overrides)['email'])->firstOrFail();
        $plain = null;

        Notification::assertSentTo($user, VerifyEmailNotification::class,
            function (VerifyEmailNotification $n) use (&$plain) {
                $plain = (new \ReflectionClass($n))->getProperty('plainToken')->getValue($n);

                return true;
            });

        return $plain;
    }

    // --- Vérification -----------------------------------------------------

    public function test_l_inscription_envoie_un_lien_de_verification(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $user = User::where('email', 'candidat@naja7i.ma')->firstOrFail();

        Notification::assertSentTo($user, VerifyEmailNotification::class);
        $this->assertDatabaseCount('verification_tokens', 1);
    }

    public function test_le_jeton_n_est_jamais_stocke_en_clair(): void
    {
        $plain = $this->registerAndCaptureToken();

        $this->assertDatabaseMissing('verification_tokens', ['token_hash' => $plain]);
        $this->assertDatabaseHas('verification_tokens', ['token_hash' => hash('sha256', $plain)]);
    }

    public function test_un_jeton_valide_verifie_le_compte(): void
    {
        $plain = $this->registerAndCaptureToken();

        $this->postJson('/api/v1/auth/email/verify', ['token' => $plain])
            ->assertOk()
            ->assertJsonPath('data.email_verified', true);

        $this->assertNotNull(User::where('email', 'candidat@naja7i.ma')->first()->email_verified_at);
    }

    public function test_un_jeton_ne_sert_qu_une_fois(): void
    {
        $plain = $this->registerAndCaptureToken();

        $this->postJson('/api/v1/auth/email/verify', ['token' => $plain])->assertOk();

        $this->postJson('/api/v1/auth/email/verify', ['token' => $plain])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'AUTH_VERIFICATION_TOKEN_INVALID');
    }

    public function test_un_jeton_expire_est_refuse(): void
    {
        $plain = $this->registerAndCaptureToken();

        VerificationToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/auth/email/verify', ['token' => $plain])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'AUTH_VERIFICATION_TOKEN_INVALID');
    }

    public function test_un_jeton_inconnu_est_refuse(): void
    {
        $this->postJson('/api/v1/auth/email/verify', ['token' => str_repeat('a', 64)])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'AUTH_VERIFICATION_TOKEN_INVALID');
    }

    public function test_un_nouvel_envoi_invalide_le_lien_precedent(): void
    {
        $ancien = $this->registerAndCaptureToken();

        app(EmailVerificationService::class)->send(
            User::where('email', 'candidat@naja7i.ma')->firstOrFail()
        );

        // Le lien intercepté avant la nouvelle demande ne doit plus fonctionner.
        $this->postJson('/api/v1/auth/email/verify', ['token' => $ancien])
            ->assertStatus(422);
    }

    public function test_le_renvoi_ne_revele_pas_si_le_compte_existe(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload());

        $connu = $this->postJson('/api/v1/auth/email/resend', ['email' => 'candidat@naja7i.ma']);
        $inconnu = $this->postJson('/api/v1/auth/email/resend', ['email' => 'personne@naja7i.ma']);

        $connu->assertStatus(202);
        $inconnu->assertStatus(202);
        $this->assertSame($connu->json('data.message'), $inconnu->json('data.message'));
    }

    public function test_le_renvoi_est_strictement_limite(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/email/resend', ['email' => 'cible@naja7i.ma']);
        }

        $this->postJson('/api/v1/auth/email/resend', ['email' => 'cible@naja7i.ma'])
            ->assertStatus(429);
    }

    // --- Blocage tant que l'e-mail n'est pas vérifié ----------------------

    public function test_l_email_non_verifie_apparait_dans_me(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload());

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('meta.email_verified', false);
    }

    public function test_apres_verification_me_reflete_l_etat(): void
    {
        $plain = $this->registerAndCaptureToken();
        $this->postJson('/api/v1/auth/email/verify', ['token' => $plain]);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('meta.email_verified', true);
    }

    // --- Mot de passe oublié ----------------------------------------------

    public function test_la_demande_envoie_un_lien_a_un_compte_existant(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload());
        $user = User::where('email', 'candidat@naja7i.ma')->firstOrFail();

        $this->postJson('/api/v1/auth/password/request', ['email' => 'candidat@naja7i.ma'])
            ->assertStatus(202);

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_la_demande_ne_revele_pas_si_le_compte_existe(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload());

        $connu = $this->postJson('/api/v1/auth/password/request', ['email' => 'candidat@naja7i.ma']);
        $inconnu = $this->postJson('/api/v1/auth/password/request', ['email' => 'personne@naja7i.ma']);

        $connu->assertStatus(202);
        $inconnu->assertStatus(202);
        $this->assertSame($connu->json('data.message'), $inconnu->json('data.message'));
    }

    public function test_le_nouveau_mot_de_passe_respecte_la_politique(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload());
        $token = Password::createToken(
            User::where('email', 'candidat@naja7i.ma')->firstOrFail()
        );

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => 'candidat@naja7i.ma',
            'password' => 'court-11car',
            'password_confirmation' => 'court-11car',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_reinitialisation_complete_puis_connexion(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload());
        $this->post('/api/v1/auth/logout');

        $user = User::where('email', 'candidat@naja7i.ma')->firstOrFail();
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => 'candidat@naja7i.ma',
            'password' => 'mon-nouveau-mot-de-passe',
            'password_confirmation' => 'mon-nouveau-mot-de-passe',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'candidat@naja7i.ma', 'password' => 'mon-nouveau-mot-de-passe',
        ])->assertOk();
    }

    public function test_un_jeton_de_reinitialisation_invalide_est_refuse(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload());

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => 'jeton-fabrique',
            'email' => 'candidat@naja7i.ma',
            'password' => 'mon-nouveau-mot-de-passe',
            'password_confirmation' => 'mon-nouveau-mot-de-passe',
        ])->assertStatus(422)->assertJsonPath('error.code', 'AUTH_RESET_TOKEN_INVALID');
    }

    public function test_les_emails_partent_dans_la_langue_du_compte(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'arabophone@naja7i.ma', 'locale' => 'ar',
        ]))->assertCreated();

        $user = User::where('email', 'arabophone@naja7i.ma')->firstOrFail();

        Notification::assertSentTo($user, VerifyEmailNotification::class,
            function (VerifyEmailNotification $n) use ($user) {
                $mail = $n->toMail($user);

                // Sujet en arabe et lien vers la version arabe du frontend.
                $this->assertSame(__('mail.verify.subject', [], 'ar'), $mail->subject);
                $this->assertStringContainsString('/ar/', $mail->actionUrl);

                return true;
            });
    }
}
