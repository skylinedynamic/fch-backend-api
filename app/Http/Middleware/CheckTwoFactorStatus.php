<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use App\Helpers\ApiResponse;

class CheckTwoFactorStatus
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

        // If user has 2FA enabled but not verified in this session
        if ($user->hasTwoFactorEnabled() && !session('2fa_verified')) {
            // Check if user can attempt verification
            if (!$user->canAttemptTwoFactorVerification()) {
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

                return ApiResponse::error(
                    __('auth.2fa_setup_required'),
                    403,
                    ['requires_2fa_setup' => true]
                );
            }

            return ApiResponse::error(
                __('auth.2fa_verification_required'),
                403,
                [
                    'requires_2fa_verification' => true,
                    'method' => $user->getTwoFactorMethod(),
                ]
            );
        }

        return $next($request);
    }
}