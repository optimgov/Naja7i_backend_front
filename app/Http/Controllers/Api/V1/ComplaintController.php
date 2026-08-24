<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\IdempotencyKeyReused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Complaints\CreateComplaintRequest;
use App\Http\Requests\Complaints\ListComplaintsRequest;
use App\Http\Requests\Complaints\ReplyComplaintRequest;
use App\Http\Resources\Complaints\ComplaintMessageResource;
use App\Http\Resources\Complaints\ComplaintThreadResource;
use App\Models\ComplaintThread;
use App\Models\User;
use App\Services\ComplaintService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ComplaintController extends Controller
{
    public function __construct(private readonly ComplaintService $complaints) {}

    public function index(ListComplaintsRequest $request): AnonymousResourceCollection
    {
        $candidate = $this->candidate($request->user());
        $perPage = (int) $request->validated('per_page', 20);

        return ComplaintThreadResource::collection(
            ComplaintThread::query()
                ->where('candidate_id', $candidate->id)
                ->orderByDesc('last_message_at')
                ->paginate($perPage),
        );
    }

    public function store(CreateComplaintRequest $request): JsonResponse
    {
        $candidate = $this->candidate($request->user());
        $data = $request->validated();

        try {
            $result = $this->complaints->createForCandidate(
                $candidate,
                $data['category'],
                trim($data['subject']),
                trim($data['body']),
                $data['idempotency_key'],
            );
        } catch (IdempotencyKeyReused) {
            return $this->idempotencyConflict();
        }

        return (new ComplaintThreadResource($result['thread']))
            ->additional(['meta' => ['replayed' => $result['replayed']]])
            ->response()
            ->setStatusCode(201);
    }

    public function show(ListComplaintsRequest $request, string $uuid): ComplaintThreadResource
    {
        $candidate = $this->candidate($request->user());

        return new ComplaintThreadResource($this->ownedThread($candidate, $uuid));
    }

    public function messages(ListComplaintsRequest $request, string $uuid): AnonymousResourceCollection
    {
        $candidate = $this->candidate($request->user());
        $thread = $this->ownedThread($candidate, $uuid);
        $perPage = (int) $request->validated('per_page', 20);

        return ComplaintMessageResource::collection(
            $thread->messages()->paginate($perPage),
        );
    }

    public function reply(ReplyComplaintRequest $request, string $uuid): JsonResponse
    {
        $candidate = $this->candidate($request->user());
        $data = $request->validated();

        try {
            $result = $this->complaints->replyAsCandidate(
                $candidate,
                $uuid,
                trim($data['body']),
                $data['idempotency_key'],
            );
        } catch (IdempotencyKeyReused) {
            return $this->idempotencyConflict();
        }

        return (new ComplaintMessageResource($result['message']))
            ->additional(['meta' => ['replayed' => $result['replayed']]])
            ->response()
            ->setStatusCode(201);
    }

    private function candidate(?User $user): User
    {
        abort_unless($user !== null && $user->hasRole('candidat'), 404);

        return $user;
    }

    private function ownedThread(User $candidate, string $uuid): ComplaintThread
    {
        return ComplaintThread::query()
            ->where('candidate_id', $candidate->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function idempotencyConflict(): JsonResponse
    {
        return ApiError::make(
            'IDEMPOTENCY_KEY_REUSED',
            __('parcours.cle_idempotence_reutilisee'),
            409,
        );
    }
}
