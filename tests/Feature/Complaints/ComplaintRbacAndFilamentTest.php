<?php

namespace Tests\Feature\Complaints;

use App\Filament\Resources\ComplaintThreads\ComplaintThreadResource;
use App\Filament\Resources\ComplaintThreads\Pages\ListComplaintThreads;
use App\Filament\Resources\ComplaintThreads\Pages\ViewComplaintThread;
use App\Models\ComplaintThread;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ComplaintService;
use App\Services\PermissionResolver;
use App\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class ComplaintRbacAndFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
        Filament::setCurrentPanel('admin');
    }

    public function test_permissions_are_platform_only_and_role_matrix_matches_contract(): void
    {
        $this->assertTrue(Permission::where('code', 'complaints.view')->firstOrFail()->platform_only);
        $this->assertTrue(Permission::where('code', 'complaints.reply')->firstOrFail()->platform_only);

        foreach (['expert_pedagogue', 'support', 'super_admin'] as $role) {
            $staff = $this->staff("{$role}@naja7i.test", $role);
            $permissions = app(PermissionResolver::class)->forUser($staff);
            $this->assertContains('complaints.view', $permissions, $role);
            $this->assertContains('complaints.reply', $permissions, $role);
        }

        $finance = $this->staff('finance-complaints@naja7i.test', 'finance');
        $this->assertNotContains('complaints.view', app(PermissionResolver::class)->forUser($finance));
        $this->assertNotContains('complaints.reply', app(PermissionResolver::class)->forUser($finance));
    }

    public function test_filament_lists_thread_and_reply_action_uses_shared_service(): void
    {
        $candidate = $this->candidate();
        $support = $this->staff('support-filament@naja7i.test', 'support');
        $thread = app(ComplaintService::class)->createForCandidate(
            $candidate, 'pedagogical', 'Correction incomprise', 'Pourquoi la réponse B ?', (string) Str::uuid7()
        )['thread'];

        Livewire::actingAs($support)
            ->test(ListComplaintThreads::class)
            ->assertCanSeeTableRecords([$thread]);

        Livewire::actingAs($support)
            ->test(ViewComplaintThread::class, ['record' => $thread->uuid])
            ->assertSuccessful()
            ->assertSee('Pourquoi la réponse B ?')
            ->assertActionVisible('reply')
            ->callAction('reply', [
                'body' => 'La justification détaillée se trouve sous chaque option.',
                'idempotency_key' => (string) Str::uuid7(),
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('complaint_messages', [
            'complaint_thread_id' => $thread->id,
            'sender_id' => $support->id,
            'sender_type' => 'staff',
        ]);
        $this->assertSame('waiting_candidate', $thread->fresh()->status);
    }

    public function test_finance_has_no_filament_access_and_resources_have_no_generic_mutation(): void
    {
        $finance = $this->staff('finance-no-complaints@naja7i.test', 'finance');
        $candidate = $this->candidate();
        $thread = app(ComplaintService::class)->createForCandidate(
            $candidate, 'other', 'Autre', 'Une demande.', (string) Str::uuid7()
        )['thread'];

        $this->actingAs($finance);
        $this->assertFalse(ComplaintThreadResource::canViewAny());
        $this->assertFalse($finance->can('view', $thread));
        $this->assertFalse($finance->can('reply', $thread));
        $this->assertFalse($finance->can('create', ComplaintThread::class));
        $this->assertFalse($finance->can('update', $thread));
        $this->assertFalse($finance->can('delete', $thread));
    }

    private function candidate(): User
    {
        $user = User::create([
            'email' => 'candidate-filament@naja7i.test',
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $user->markEmailAsVerified();
        $user->grantCandidateRole();

        return $user;
    }

    private function staff(string $email, string $role): User
    {
        $user = User::create([
            'email' => $email,
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $user->markEmailAsVerified();
        $user->memberships()->create([
            'role_id' => Role::where('code', $role)->whereNull('tenant_id')->value('id'),
        ]);

        return $user;
    }
}
