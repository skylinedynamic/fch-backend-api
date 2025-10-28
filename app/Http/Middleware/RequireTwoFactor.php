<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use App\Helpers\ApiResponse;
use Illuminate\Support\Facades\Auth;

class RequireTwoFactor
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (!$user) {
            return $next($request);
        }

        if (!$user->hasTwoFactorEnabled()) {
            return ApiResponse::error(__('auth.2fa_required'),403,
                ['requires_2fa_setup' => true]
            );
        }

        if ($user->isTwoFactorLocked()) {
            $remainingTime = $user->getTwoFactorLockoutTimeRemaining();
            return ApiResponse::error(__('auth.2fa_locked', ['seconds' => $remainingTime]),423,
                [
                    'locked_until' => $user->twoFaAuthSetting->locked_until,
                    'remaining_seconds' => $remainingTime,
                ]
            );
        }

        return $next($request);
    }
}