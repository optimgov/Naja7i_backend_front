<?php

namespace App\Services;

use App\Exceptions\IdempotencyKeyReused;
use App\Models\ComplaintMessage;
use App\Models\ComplaintThread;
use App\Models\User;
use App\Support\UniqueViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Toute écriture de réclamation, API comme Filament, passe ici. */
final class ComplaintService
{
    private const IDEMPOTENCY_INDEX = 'complaint_messages_tenant_sender_idempotency_unique';

    public function __construct(private readonly PermissionResolver $permissions) {}

    /** @return array{thread: ComplaintThread, message: ComplaintMessage, replayed: bool} */
    public function createForCandidate(
        User $candidate,
        string $category,
        string $subject,
        string $body,
        string $idempotencyKey,
    ): array {
        $operation = 'create_thread';
        $fingerprint = $this->fingerprint($operation, [
            'category' => $category,
            'subject' => $subject,
            'body' => $body,
        ]);

        try {
            return DB::transaction(function () use (
                $candidate, $category, $subject, $body, $idempotencyKey, $operation, $fingerprint
            ): array {
                if ($existing = $this->existing($candidate, $idempotencyKey, $fingerprint)) {
                    return $existing;
                }

                $now = now();
                $thread = ComplaintThread::create([
                    'candidate_id' => $candidate->id,
                    'category' => $category,
                    'subject' => $subject,
                    'status' => 'waiting_staff',
                    'last_message_at' => $now,
                ]);
                $message = $this->append(
                    $thread, $candidate, 'candidate', $body, $idempotencyKey, $fingerprint, $operation, $now
                );

                return ['thread' => $thread, 'message' => $message, 'replayed' => false];
            });
        } catch (QueryException $exception) {
            if (! UniqueViolation::on($exception, self::IDEMPOTENCY_INDEX)) {
                throw $exception;
            }

            return $this->existing($candidate, $idempotencyKey, $fingerprint)
                ?? throw $exception;
        }
    }

    /** @return array{thread: ComplaintThread, message: ComplaintMessage, replayed: bool} */
    public function replyAsCandidate(
        User $candidate,
        string $threadUuid,
        string $body,
        string $idempotencyKey,
    ): array {
        return $this->reply(
            actor: $candidate,
            threadUuid: $threadUuid,
            body: $body,
            idempotencyKey: $idempotencyKey,
            senderType: 'candidate',
            status: 'waiting_staff',
            operation: 'candidate_reply',
            candidateId: $candidate->id,
        );
    }

    /** @return array{thread: ComplaintThread, message: ComplaintMessage, replayed: bool} */
    public function replyAsStaff(
        User $actor,
        ComplaintThread $thread,
        string $body,
        string $idempotencyKey,
    ): array {
        if (! $this->permissions->has($actor, 'complaints.reply')) {
            throw new AuthorizationException('La permission complaints.reply est requise.');
        }

        return $this->reply(
            actor: $actor,
            threadUuid: $thread->uuid,
            body: $body,
            idempotencyKey: $idempotencyKey,
            senderType: 'staff',
            status: 'waiting_candidate',
            operation: 'staff_reply',
        );
    }

    /**
     * @return array{thread: ComplaintThread, message: ComplaintMessage, replayed: bool}
     */
    private function reply(
        User $actor,
        string $threadUuid,
        string $body,
        string $idempotencyKey,
        string $senderType,
        string $status,
        string $operation,
        ?int $candidateId = null,
    ): array {
        $fingerprint = $this->fingerprint($operation, ['thread' => $threadUuid, 'body' => $body]);

        try {
            return DB::transaction(function () use (
                $actor, $threadUuid, $body, $idempotencyKey, $senderType,
                $status, $operation, $candidateId, $fingerprint
            ): array {
                if ($existing = $this->existing($actor, $idempotencyKey, $fingerprint)) {
                    return $existing;
                }

                $threadQuery = ComplaintThread::query()->where('uuid', $threadUuid);

                if ($candidateId !== null) {
                    $threadQuery->where('candidate_id', $candidateId);
                }

                /** @var ComplaintThread $thread */
                $thread = $threadQuery->lockForUpdate()->firstOrFail();
                $now = now();
                $message = $this->append(
                    $thread, $actor, $senderType, $body, $idempotencyKey, $fingerprint, $operation, $now
                );
                $thread->update(['status' => $status, 'last_message_at' => $now]);

                return ['thread' => $thread->fresh(), 'message' => $message, 'replayed' => false];
            });
        } catch (QueryException $exception) {
            if (! UniqueViolation::on($exception, self::IDEMPOTENCY_INDEX)) {
                throw $exception;
            }

            return $this->existing($actor, $idempotencyKey, $fingerprint)
                ?? throw $exception;
        }
    }

    private function append(
        ComplaintThread $thread,
        User $sender,
        string $senderType,
        string $body,
        string $idempotencyKey,
        string $fingerprint,
        string $operation,
        mixed $createdAt,
    ): ComplaintMessage {
        return ComplaintMessage::create([
            'complaint_thread_id' => $thread->id,
            'sender_id' => $sender->id,
            'sender_type' => $senderType,
            'body' => $body,
            'idempotency_key' => $idempotencyKey,
            'idempotency_fingerprint' => $fingerprint,
            'operation' => $operation,
            'created_at' => $createdAt,
        ]);
    }

    /** @return array{thread: ComplaintThread, message: ComplaintMessage, replayed: bool}|null */
    private function existing(User $actor, string $key, string $fingerprint): ?array
    {
        $message = ComplaintMessage::query()
            ->where('sender_id', $actor->id)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();

        if ($message === null) {
            return null;
        }

        if (! hash_equals($message->idempotency_fingerprint, $fingerprint)) {
            throw new IdempotencyKeyReused($key);
        }

        return [
            'thread' => $message->thread()->firstOrFail(),
            'message' => $message,
            'replayed' => true,
        ];
    }

    /** @param array<string, string> $payload */
    private function fingerprint(string $operation, array $payload): string
    {
        $encoded = json_encode(
            ['operation' => $operation, 'payload' => $payload],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return hash('sha256', $encoded);
    }
}
