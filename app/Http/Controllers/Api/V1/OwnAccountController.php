<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\OwnAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OwnAccountController extends Controller
{
    public function __construct(private readonly OwnAccountService $accounts) {}

    public function update(Request $request): JsonResponse
    {
        $user = $this->accounts->update($request->user(), $request->all());

        return (new UserResource($user->load('memberships.role')))->response();
    }

    public function password(Request $request): JsonResponse
    {
        $this->accounts->changePassword($request->user(), $request->all());

        return response()->json(['data' => ['message' => __('auth.password_updated')]]);
    }
}
