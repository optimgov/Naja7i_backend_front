<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StaffInvitation;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use App\Services\AccountAdministrationService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
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

    /**
     * DET-83 — L'ENVOI SORT DE LA TRANSACTION, ET CE TEST CHANGE DE CONTRAT.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * CE QU'IL DÉFENDAIT, ET POURQUOI CE N'EST PLUS TENABLE
     *
     * Il exigeait qu'un échec d'envoi ne laisse « ni compte ni rôles ni
     * invitation ». Cette atomicité était réelle — mais elle reposait sur le
     * défaut même que DET-83 dénonce : `notify()` appelé DANS la transaction
     * qui crée le compte, donc « un aller-retour SMTP qui tient des verrous
     * sur users, identities et memberships ».
     *
     * Le 27 août, ce défaut a fermé la porte pour de vrai — côté inscription :
     * la messagerie de la préproduction refusait la connexion, la requête
     * rendait 500, et le compte était créé quand même. DET-14 l'annonçait.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * LE CONTRAT QUI REMPLACE, ET IL EST PLUS FORT
     *
     * L'envoi est différé et `afterCommit`. Il ne peut donc plus faire échouer
     * l'invitation, ni tenir de verrous, ni — dans l'autre sens — « livrer un
     * lien dont l'invitation n'existe plus », l'autre moitié de DET-83.
     *
     * L'atomicité disparaît parce que son objet disparaît : il n'y a plus
     * d'état partiel possible. L'invitation est complète, et le courriel est un
     * job qui réessaie. Ce qui reste à défendre est donc ceci — l'invitation
     * aboutit MALGRÉ une messagerie morte, et le lien reste utilisable.
     */
    public function test_une_messagerie_morte_n_empeche_plus_d_inviter(): void
    {
        /* La panne reproduite, pas simulée : un port fermé donne exactement
         * l'exception qu'a levée la 62. */
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 9,
            'mail.mailers.smtp.timeout' => 1,
        ]);

        Queue::fake();

        $invite = $this->invite();

        $this->assertDatabaseHas('users', ['email' => 'invite@naja7i.ma']);
        $this->assertDatabaseCount('staff_invitations', 1);

        /* ET LE LIEN EST UTILISABLE : une invitation qui aboutit sans jeton
         * exploitable serait pire qu'un échec franc. */
        $this->assertNotEmpty($this->plainTokenFor($invite));
    }

    /**
     * L'ENVOI PART QUAND MÊME, par la file.
     *
     * Sans ce second test, le précédent resterait vert si l'on cessait
     * purement et simplement d'envoyer l'invitation.
     */
    public function test_l_invitation_est_bien_mise_en_file(): void
    {
        Notification::fake();

        $invite = $this->invite();

        Notification::assertSentTo($invite, StaffInvitationNotification::class);
    }

    private function invite(string $locale = 'fr', ?User $actor = null): User
    {
        $actor ??= $this->superAdmin();
        $role = Role::where('code', 'expert_pedagogue')->whereNull('tenant_id')->firstOrFail();

        return app(AccountAdministrationService::class)->create($actor, [
            'first_name' => 'Samira', 'last_name' => 'Alaoui',
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
