<?php

namespace Tests\Feature;

use App\Models\Identity;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class OwnAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
    }

    public function test_candidat_et_personnel_mutent_le_meme_contrat_de_propre_compte(): void
    {
        foreach (['candidat' => '1', 'auteur' => '2'] as $role => $suffix) {
            $this->app['session']->flush();
            $user = $this->user("{$role}@naja7i.ma", $role);
            $user->forceFill([
                'email_verified_at' => now(),
                'phone' => "+21260000000{$suffix}",
                'phone_verified_at' => now(),
            ])->save();

            $this->actingAs($user)
                ->patchJson('/api/v1/me/account', [
                    'email' => "nouveau-{$role}@naja7i.ma",
                    'phone' => "+21260000001{$suffix}",
                    'locale' => 'ar',
                    'status' => 'suspended',
                    'role_uuids' => [],
                    'tenant_id' => 999,
                    'terms_accepted' => false,
                ])
                ->assertOk()
                ->assertJsonPath('data.locale', 'ar');

            $user->refresh();
            $this->assertNull($user->email_verified_at);
            $this->assertNull($user->phone_verified_at);
            $this->assertSame('active', $user->status);
            $this->assertSame([$role], $user->memberships()->with('role')->get()->pluck('role.code')->all());
        }
    }

    public function test_changement_de_mot_de_passe_exige_le_courant_et_la_politique_centrale(): void
    {
        config()->set('naja7i.password.check_compromised', false);
        $user = $this->user('mot-de-passe@naja7i.ma', 'candidat');

        $this->actingAs($user)
            ->putJson('/api/v1/me/password', [
                'current_password' => 'incorrect',
                'password' => 'nouvelle-phrase-solide',
                'password_confirmation' => 'nouvelle-phrase-solide',
            ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->actingAs($user)
            ->putJson('/api/v1/me/password', [
                'current_password' => 'une-phrase-de-passe-solide',
                'password' => 'court',
                'password_confirmation' => 'court',
            ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->actingAs($user)
            ->putJson('/api/v1/me/password', [
                'current_password' => 'une-phrase-de-passe-solide',
                'password' => 'nouvelle-phrase-solide',
                'password_confirmation' => 'nouvelle-phrase-solide',
            ])->assertOk();

        $this->assertTrue(Hash::check('nouvelle-phrase-solide', $user->fresh()->password));
    }

    public function test_un_compte_suspendu_ne_peut_plus_muter_son_dossier_depuis_une_session_existante(): void
    {
        $user = $this->user('suspendu@naja7i.ma', 'auteur');
        $this->actingAs($user);
        $user->forceFill(['status' => 'suspended'])->save();

        $this->patchJson('/api/v1/me/account', ['locale' => 'ar'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_ACCOUNT_SUSPENDED');
        $this->assertSame('fr', $user->fresh()->locale);
    }

    private function user(string $email, string $roleCode): User
    {
        $user = User::create([
            'email' => $email,
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        Identity::create(['user_id' => $user->id, 'provider' => 'password']);
        $user->memberships()->create([
            'role_id' => Role::where('code', $roleCode)->whereNull('tenant_id')->value('id'),
        ]);

        return $user;
    }
}
