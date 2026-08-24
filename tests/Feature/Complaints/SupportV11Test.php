<?php

namespace Tests\Feature\Complaints;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PermissionResolver;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupportV11Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
    }

    public function test_support_ne_porte_que_la_lecture_et_la_reponse_aux_reclamations(): void
    {
        $support = $this->membre('support-cible@naja7i.test', 'support');

        $this->assertEqualsCanonicalizing(
            ['complaints.view', 'complaints.reply'],
            app(PermissionResolver::class)->forUser($support),
        );
    }

    public function test_le_resserrement_ne_reduit_ni_expert_finance_ni_super_admin(): void
    {
        $expert = app(PermissionResolver::class)->forUser($this->membre('expert-cible@naja7i.test', 'expert_pedagogue'));
        $finance = app(PermissionResolver::class)->forUser($this->membre('finance-cible@naja7i.test', 'finance'));
        $admin = app(PermissionResolver::class)->forUser($this->membre('admin-cible@naja7i.test', 'super_admin'));

        $this->assertContains('questions.publish', $expert);
        $this->assertNotContains('complaints.view', $expert);
        $this->assertNotContains('complaints.reply', $expert);
        $this->assertContains('orders.validate', $finance);
        $this->assertNotContains('complaints.view', $finance);
        $this->assertCount(Permission::count(), $admin);
    }

    public function test_le_rollback_retablit_l_etape_compatible_puis_le_rejeu_resserre(): void
    {
        $migration = require database_path('migrations/0001_01_01_000840_resserrer_le_role_support.php');
        $support = Role::whereNull('tenant_id')->where('code', 'support')->firstOrFail();

        $migration->down();
        $apresRetour = $support->fresh()->permissions()->pluck('code');

        foreach (['questions.view', 'catalogue.view', 'members.view', 'users.support', 'grants.manage'] as $permission) {
            $this->assertContains($permission, $apresRetour);
        }
        $this->assertContains('complaints.view', $apresRetour);
        $this->assertContains('complaints.reply', $apresRetour);

        $expert = Role::whereNull('tenant_id')->where('code', 'expert_pedagogue')->firstOrFail();
        $this->assertContains('complaints.view', $expert->permissions()->pluck('code'));
        $this->assertContains('complaints.reply', $expert->permissions()->pluck('code'));

        $migration->up();

        $this->assertEqualsCanonicalizing(
            ['complaints.view', 'complaints.reply'],
            $support->fresh()->permissions()->pluck('code')->all(),
        );
        $this->assertNotContains('complaints.view', $expert->fresh()->permissions()->pluck('code'));
        $this->assertNotContains('complaints.reply', $expert->fresh()->permissions()->pluck('code'));
    }

    private function membre(string $email, string $role): User
    {
        $user = User::create([
            'email' => $email,
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $user->memberships()->create([
            'role_id' => Role::whereNull('tenant_id')->where('code', $role)->value('id'),
        ]);

        return $user;
    }
}
