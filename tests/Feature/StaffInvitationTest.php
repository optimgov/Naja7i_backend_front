<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StaffInvitation;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use App\Services\AccountAdministrationService;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Notifications\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

final class StaffInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
        config()->set('naja7i.password.check_compromised', false);
        Notification::fake();
    }

    public function test_invitation_est_hashee_localisee_et_le_compte_inutilisable_avant_acceptation(): void
    {
        config()->set('app.frontend_url', 'https://www.naja7i.test');
        $user = $this->invite('ar');
        $invitation = StaffInvitation::where('user_id', $user->id)->firstOrFail();

        Notification::assertSentTo($user, StaffInvitationNotification::class, function ($notification) use ($invitation): bool {
            /** @var MailMessage $mail */
            $mail = $notification->toMail($notificationUser = User::where('email', 'invite@naja7i.ma')->firstOrFail());
            $url = $mail->actionUrl;
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            $this->assertStringStartsWith('https://www.naja7i.test/ar/invitation-personnel?', $url);
            $this->assertSame(hash('sha256', $query['token']), $invitation->token_hash);
            $this->assertDatabaseMissing('staff_invitations', ['token_hash' => $query['token']]);

            return true;
        });

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'nimporte-quoi',
        ])->assertUnauthorized();
        $this->assertNull($user->password);
        $this->assertFalse($user->identities()->exists());
        $this->assertArrayNotHasKey('token_hash', $invitation->toArray());
    }

    public function test_acceptation_est_unique_et_active_la_connexion(): void
    {
        $user = $this->invite();
        $token = $this->plainTokenFor($user);

        $payload = [
            'token' => $token,
            'password' => 'nouvelle-phrase-solide',
            'password_confirmation' => 'nouvelle-phrase-solide',
        ];
        $this->postJson('/api/v1/auth/staff-invitations/accept', $payload)->assertOk();
        $this->postJson('/api/v1/auth/staff-invitations/accept', $payload)
            ->assertUnprocessable()->assertJsonPath('error.code', 'AUTH_INVITATION_INVALID');

        $this->assertTrue(Hash::check('nouvelle-phrase-solide', $user->fresh()->password));
        $this->assertTrue($user->identities()->where('provider', 'password')->exists());
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'nouvelle-phrase-solide',
        ])->assertOk();
    }

    public function test_expiration_et_reinvitation_invalident_sans_reveler_la_cause(): void
    {
        $actor = $this->superAdmin();
        $user = $this->invite(actor: $actor);
        $ancien = $this->plainTokenFor($user);
        StaffInvitation::where('user_id', $user->id)->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/auth/staff-invitations/accept', [
            'token' => $ancien, 'password' => 'nouvelle-phrase-solide',
            'password_confirmation' => 'nouvelle-phrase-solide',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'AUTH_INVITATION_INVALID');

        app(AccountAdministrationService::class)->reinvite($actor, $user);
        $nouveau = $this->plainTokenFor($user);
        $this->assertNotSame($ancien, $nouveau);
        $this->assertNotNull(StaffInvitation::where('token_hash', hash('sha256', $ancien))->value('revoked_at'));
    }

    public function test_un_echec_d_envoi_ne_laisse_ni_compte_ni_roles_ni_invitation_partiels(): void
    {
        $manager = new ChannelManager(app());
        app()->instance(ChannelManager::class, $manager);
        app()->instance(Dispatcher::class, $manager);
        app()->instance(Factory::class, $manager);
        Notification::swap($manager);

        $mail = \Mockery::mock(MailChannel::class);
        $mail->shouldReceive('send')->once()->andThrow(new RuntimeException('Transport indisponible'));
        app()->instance(MailChannel::class, $mail);

        try {
            $this->invite();
            $this->fail("L'échec d'envoi aurait dû remonter.");
        } catch (RuntimeException $exception) {
            $this->assertSame('Transport indisponible', $exception->getMessage());
            $this->assertDatabaseMissing('users', ['email' => 'invite@naja7i.ma']);
            $this->assertDatabaseCount('staff_invitations', 0);
        }
    }

    private function invite(string $locale = 'fr', ?User $actor = null): User
    {
        $actor ??= $this->superAdmin();
        $role = Role::where('code', 'expert_pedagogue')->whereNull('tenant_id')->firstOrFail();

        return app(AccountAdministrationService::class)->create($actor, [
            'email' => 'invite@naja7i.ma', 'phone' => null, 'locale' => $locale,
            'status' => 'active', 'role_uuids' => [$role->uuid],
        ]);
    }

    private function plainTokenFor(User $user): string
    {
        $notification = Notification::sent($user, StaffInvitationNotification::class)->last();
        $this->assertNotNull($notification);
        parse_str((string) parse_url($notification->toMail($user)->actionUrl, PHP_URL_QUERY), $query);

        return $query['token'];
    }

    private function superAdmin(): User
    {
        $user = User::create([
            'email' => uniqid('admin-', true).'@naja7i.ma',
            'password' => 'une-phrase-de-passe-solide', 'locale' => 'fr', 'status' => 'active',
        ]);
        $user->memberships()->create([
            'role_id' => Role::where('code', 'super_admin')->whereNull('tenant_id')->value('id'),
        ]);

        return $user;
    }
}
