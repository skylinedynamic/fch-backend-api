<?php

namespace App\Services\Common\Auth;

use App\Helpers\ApiResponse;
use App\Models\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class AuthService
{
    /**
     * Handle user login with rate limiting and detailed error feedback
     *
     * @param array $credentials
     * @param Request $request
     * @return JsonResponse
     */
    public function login(array $credentials, Request $request): JsonResponse
    {
        $authCredentials = collect($credentials)->except('recaptcha')->toArray();
        
        $email = (string) $credentials['email'];
        $key = 'login:' . $email . $request->ip();
        $maxAttempts = config('app.login_throttle_limit');
        $decayMinutes = config('app.login_decay_minutes');
        
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $attempts = RateLimiter::attempts($key);
            $remainingAttempts = max(0, $maxAttempts - $attempts);
            
            return ApiResponse::tooManyRequests(__('auth.too_many_login_attempts',[
                'time' => $decayMinutes,
            ]));
        }
        
        $user = User::where('email', $authCredentials['email'])->first();
        
        if (!$user) {
            RateLimiter::hit($key, $decayMinutes * 60);
            $attempts = RateLimiter::attempts($key);
            $remainingAttempts = max(0, $maxAttempts - $attempts);
            
            return ApiResponse::loginError(__('auth.invalid_email'), 'email', $remainingAttempts, $maxAttempts);
        }

        if (!Auth::attempt($authCredentials)) {
            RateLimiter::hit($key, $decayMinutes * 60);
            $attempts = RateLimiter::attempts($key);
            $remainingAttempts = max(0, $maxAttempts - $attempts);
            
            return ApiResponse::loginError(__('auth.invalid_password'), 'password', $remainingAttempts, $maxAttempts);
        }

        RateLimiter::clear($key);
        
        $user = Auth::user();
        
        
        $token = $user->createToken(__('auth.token_name'))->accessToken;

        $attempts = RateLimiter::attempts($key);
        $remainingAttempts = max(0, $maxAttempts - $attempts);

        return ApiResponse::success([
            'token' => $token,
            'user' => $user,
        ], __('auth.logged_in'));
    }


}