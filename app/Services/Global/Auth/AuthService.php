<?php

namespace App\Services\Global\Auth;

use App\Models\User\User;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use App\Models\Partner\Partner;
use App\Models\Borrower\Borrower;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Models\TwoFaAuth\TwoFaAuthSetting;
use Illuminate\Support\Facades\RateLimiter;
use App\Services\Global\TwoFaAuth\TwoFaService;
use App\Http\Resources\Global\Auth\UserResource;

class AuthService
{
    /**
     * Handle user login with rate limiting and detailed error feedback
     *
     * @param array $credentials
     * @param Request $request
     * @return JsonResponse
     */
    public function login(array $credentials, Request $request, $user_type = null): JsonResponse
    {
        $authCredentials = collect($credentials)->except('recaptcha')->toArray();

        $email = (string) $credentials['email'];
        $key = 'login:' . $email . $request->ip();

        $maxAttempts = config('app.login_throttle_limit');
        $decayMinutes = config('app.login_decay_minutes');

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $attempts = RateLimiter::attempts($key);
            $remainingAttempts = max(0, $maxAttempts - $attempts);

            return ApiResponse::tooManyRequests(__('auth.too_many_login_attempts', [
                'time' => $decayMinutes,
            ]));
        }

        $user = User::where('email', $authCredentials['email'])
            ->when($user_type, function ($query) use ($user_type) {
                $query->where('user_type', $user_type);
            }, function ($query) {
                $query->whereIn('user_type', [User::ADMIN, User::PARTNER]);
            })
            ->first();


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

        // If 2FA is enabled, return token and 2FA details instead of user details
        $twoFaSetting = $user->twoFaAuthSetting()->first();
        if ($twoFaSetting && $twoFaSetting->enabled) {
            $method = $twoFaSetting->method;

            // For SMS/Email methods, trigger a new OTP code via TwoFaService
            if (in_array($method, [TwoFaAuthSetting::SMS, TwoFaAuthSetting::EMAIL])) {
                $twoFaService = app(TwoFaService::class);
                $twoFaService->resend($user, $method);
            }

            return ApiResponse::success([
                'user_id' => $user->id,
                'two_factor_enabled' => (bool) $twoFaSetting->enabled,
                'two_factor_method' => $method,
            ], __('auth.2fa_verification_required'));
        }


        $token = $user->createToken(__('auth.token_name'))->accessToken;

        // Eager-load relations needed by UserResource to avoid lazy loading
        $relations = ['roles.permissions', 'permissions', 'partner'];

        if (in_array($user->entity_type, [Borrower::class, Partner::class])) {
            $relations[] = 'entity.subscription.enrollmentPhase';
            $relations[] = 'entity.subscription.platformFeePhase';
        }

        $user->load($relations);

        $attempts = RateLimiter::attempts($key);
        $remainingAttempts = max(0, $maxAttempts - $attempts);

        return ApiResponse::success([
            'token' => $token,
            'user' => new UserResource($user),
        ], __('auth.logged_in'));
    }

    /**
     * Handle user logout by revoking the current access token
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return ApiResponse::unauthorized(__('auth.unauthenticated'));
            }

            // Revoke the current access token
            $token = $user->token();
            if ($token) {
                $token->revoke();
            }

            return ApiResponse::success([], __('auth.logged_out'));
        } catch (\Exception $e) {
            return ApiResponse::error(__('auth.logout_failed'), 500);
        }
    }
}
