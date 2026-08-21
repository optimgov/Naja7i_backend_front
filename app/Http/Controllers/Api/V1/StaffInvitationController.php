<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\StaffInvitationService;
use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class StaffInvitationController extends Controller
{
    public function __construct(private readonly StaffInvitationService $invitations) {}

    public function accept(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed'],
        ]);

        try {
            $this->invitations->accept(
                $validated['token'],
                $validated['password'],
                $request->string('password_confirmation')->value(),
            );
        } catch (ValidationException $exception) {
            if (array_key_exists('token', $exception->errors())) {
                return ApiError::make('AUTH_INVITATION_INVALID', __('auth.invitation_invalid'), 422);
            }
            throw $exception;
        }

        return response()->json(['data' => ['message' => __('auth.invitation_accepted')]]);
    }
}
