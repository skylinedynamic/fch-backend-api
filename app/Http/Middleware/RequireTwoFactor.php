<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use App\Helpers\ApiResponse;

class RequireTwoFactor
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // If user is not authenticated, let other middleware handle it
        if (!$user) {
            return $next($request);
        }

        // Check if user has 2FA enabled
        if (!$user->hasTwoFactorEnabled()) {
            return ApiResponse::error(
                __('auth.2fa_required'),
                403,
                ['requires_2fa_setup' => true]
            );
        }

        // Check if user is locked out
        if ($user->isTwoFactorLocked()) {
            $remainingTime = $user->getTwoFactorLockoutTimeRemaining();
            return ApiResponse::error(
                __('auth.2fa_locked', ['seconds' => $remainingTime]),
                423,
                [
                    'locked_until' => $user->twoFaAuthSetting->locked_until,
                    'remaining_seconds' => $remainingTime,
                ]
            );
        }

        return $next($request);
    }
}