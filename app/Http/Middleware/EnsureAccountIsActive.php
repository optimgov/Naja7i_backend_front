<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->status !== 'active') {
            return ApiError::make('AUTH_ACCOUNT_SUSPENDED', __('auth.account_suspended'), 403);
        }

        return $next($request);
    }
}
