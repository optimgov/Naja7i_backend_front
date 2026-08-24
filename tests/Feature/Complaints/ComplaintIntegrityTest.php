<?php

namespace Tests\Feature\Complaints;

use App\Models\ComplaintMessage;
use App\Models\ComplaintThread;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ComplaintService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ComplaintIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(Tenant::where('kind', 'platform')->firstOrFail());
        $this->candidate = User::create([
            'email' => 'candidate-integrity@naja7i.test',
            'password' => 'une-phrase-de-passe-solide',
            'locale' => 'fr',
            'status' => 'active',
        ]);
        $this->candidate->markEmailAsVerified();
        $this->candidate->grantCandidateRole();
    }

    public function test_message_cannot_be_updated(): void
    {
        $message = $this->createdMessage();

        $this->expectException(QueryException::class);
        DB::table('complaint_messages')->where('id', $message->id)->update(['body' => 'Réécrit']);
    }

    public function test_message_cannot_be_deleted(): void
    {
        $message = $this->createdMessage();

        $this->expectException(QueryException::class);
        DB::table('complaint_messages')->where('id', $message->id)->delete();
    }

    public function test_thread_cannot_be_deleted(): void
    {
        $thread = app(ComplaintService::class)->createForCandidate(
            $this->candidate, 'technical', 'Sujet', 'Message initial.', (string) Str::uuid7()
        )['thread'];

        $this->expectException(QueryException::class);
        DB::table('complaint_threads')->where('id', $thread->id)->delete();
    }

    public function test_tenant_scope_hides_threads_and_messages_from_another_tenant(): void
    {
        app(ComplaintService::class)->createForCandidate(
            $this->candidate, 'account', 'Compte', 'Message isolé.', (string) Str::uuid7()
        );

        $other = Tenant::create([
            'slug' => 'other-complaints',
            'name' => 'Autre tenant',
            'kind' => 'organization',
            'status' => 'active',
        ]);
        app(TenantContext::class)->set($other);

        $this->assertDatabaseCount('complaint_threads', 1);
        $this->assertSame(0, ComplaintThread::query()->count());
        $this->assertSame(0, ComplaintMessage::query()->count());
    }

    private function createdMessage(): ComplaintMessage
    {
        return app(ComplaintService::class)->createForCandidate(
            $this->candidate, 'other', 'Sujet', 'Message initial.', (string) Str::uuid7()
        )['message'];
    }
}
