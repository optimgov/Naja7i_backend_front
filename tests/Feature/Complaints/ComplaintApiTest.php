<?php

namespace Tests\Feature\Complaints;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ComplaintService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ComplaintApiTest extends TestCase
{
    use RefreshDatabase;

    private User $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
        $this->candidate = $this->candidate('candidate-complaints@naja7i.test');
    }

    public function test_verified_candidate_creates_lists_reads_and_replies_to_own_thread(): void
    {
        $key = (string) Str::uuid7();

        $created = $this->actingAs($this->candidate)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/me/complaints', [
                'category' => 'technical',
                'subject' => 'Le diagnostic ne démarre pas',
                'body' => 'Le bouton revient toujours au tableau de bord.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'waiting_staff')
            ->assertJsonPath('meta.replayed', false)
            ->assertJsonMissing(['candidate_id', 'tenant_id', 'sender_id']);

        $uuid = $created->json('data.uuid');

        $this->actingAs($this->candidate)->getJson('/api/v1/me/complaints')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $uuid);

        $this->actingAs($this->candidate)->getJson("/api/v1/me/complaints/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.subject', 'Le diagnostic ne démarre pas');

        $this->actingAs($this->candidate)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/me/complaints/{$uuid}/messages", ['body' => 'Le problème persiste.'])
            ->assertCreated()
            ->assertJsonPath('data.sender', 'candidate');

        $this->actingAs($this->candidate)->getJson("/api/v1/me/complaints/{$uuid}/messages")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_same_idempotency_key_replays_and_different_content_returns_409(): void
    {
        $key = (string) Str::uuid7();
        $payload = [
            'category' => 'account',
            'subject' => 'Mon profil',
            'body' => 'Je ne retrouve pas mon épreuve.',
        ];

        $first = $this->actingAs($this->candidate)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/me/complaints', $payload)
            ->assertCreated();
        $uuid = $first->json('data.uuid');

        $this->actingAs($this->candidate)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/me/complaints', $payload)
            ->assertCreated()
            ->assertJsonPath('data.uuid', $uuid)
            ->assertJsonPath('meta.replayed', true);

        $this->assertDatabaseCount('complaint_threads', 1);
        $this->assertDatabaseCount('complaint_messages', 1);

        $this->actingAs($this->candidate)->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/me/complaints', [...$payload, 'body' => 'Un autre contenu.'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_foreign_thread_is_404_for_show_messages_and_reply(): void
    {
        $owner = $this->candidate('other-candidate@naja7i.test');
        $thread = app(ComplaintService::class)->createForCandidate(
            $owner, 'other', 'Question privée', 'Mon message privé.', (string) Str::uuid7()
        )['thread'];

        $this->actingAs($this->candidate)->getJson("/api/v1/me/complaints/{$thread->uuid}")->assertNotFound();
        $this->actingAs($this->candidate)->getJson("/api/v1/me/complaints/{$thread->uuid}/messages")->assertNotFound();
        $this->actingAs($this->candidate)->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson("/api/v1/me/complaints/{$thread->uuid}/messages", ['body' => 'Intrusion'])
            ->assertNotFound();
    }

    public function test_candidate_api_never_exposes_staff_identity(): void
    {
        $thread = app(ComplaintService::class)->createForCandidate(
            $this->candidate, 'payment', 'Coupon', 'Mon coupon attend.', (string) Str::uuid7()
        )['thread'];
        $support = $this->staff('support-identity@naja7i.test', 'support');

        app(ComplaintService::class)->replyAsStaff(
            $support, $thread, 'Nous vérifions votre coupon.', (string) Str::uuid7()
        );

        $response = $this->actingAs($this->candidate)
            ->getJson("/api/v1/me/complaints/{$thread->uuid}/messages")
            ->assertOk()
            ->assertJsonPath('data.1.sender', 'staff');

        $staffMessage = $response->json('data.1');
        $this->assertSame(['uuid', 'sender', 'body', 'created_at'], array_keys($staffMessage));
        $this->assertStringNotContainsString($support->email, $response->getContent());
        $this->assertSame('waiting_candidate', $thread->fresh()->status);
    }

    public function test_verified_non_candidate_cannot_use_candidate_api(): void
    {
        $support = $this->staff('support-api@naja7i.test', 'support');

        $this->actingAs($support)->getJson('/api/v1/me/complaints')->assertNotFound();
    }

    public function test_pagination_is_bounded_to_50(): void
    {
        $this->actingAs($this->candidate)->getJson('/api/v1/me/complaints?per_page=51')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_pagination_has_a_stable_tie_breaker_when_messages_share_a_timestamp(): void
    {
        $first = app(ComplaintService::class)->createForCandidate(
            $this->candidate, 'other', 'Première', 'Premier message.', (string) Str::uuid7()
        )['thread'];
        $second = app(ComplaintService::class)->createForCandidate(
            $this->candidate, 'other', 'Seconde', 'Second message.', (string) Str::uuid7()
        )['thread'];

        $first->update(['last_message_at' => $second->last_message_at]);

        $this->actingAs($this->candidate)->getJson('/api/v1/me/complaints?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $second->uuid);
    }

    public function test_post_requires_idempotency_key_and_accepts_only_contract_categories(): void
    {
        $this->actingAs($this->candidate)->postJson('/api/v1/me/complaints', [
            'category' => 'commercial',
            'subject' => 'Sujet',
            'body' => 'Message',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category', 'idempotency_key']);
    }

    public function test_subject_and_messages_containing_only_spaces_are_rejected_as_validation_errors(): void
    {
        $this->actingAs($this->candidate)
            ->withHeader('Idempotency-Key', (string) Str::uuid7())
            ->postJson('/api/v1/me/complaints', [
                'category' => 'other',
                'subject' => '   ',
                'body' => " \t ",
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject', 'body']);
    }

    private function candidate(string $email): User
    {
        $user = User::create([
            'email' => $email,
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
