<?php

namespace App\Services\Global\Auth;

use App\Helpers\ApiResponse;
use App\Http\Resources\Global\Auth\UserResource;
use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\Subscription\Subscription;
use App\Models\TwoFaAuth\TwoFaAuthSetting;
use App\Models\User\User;
use App\Services\Global\Common\PortalIsolationService;
use App\Services\Global\TwoFaAuth\TwoFaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        // 0. Portal & Domain Isolation Check
        $portalIsolationService = app(PortalIsolationService::class);
        $domain = $portalIsolationService->resolveDomain($request);
        if (!$portalIsolationService->isUserAllowedOnDomain($user, $domain)) {
            return ApiResponse::loginError($portalIsolationService->getUnauthorizedMessage($user, $domain), 'email');
        }

        $userSeatSubscription = Subscription::where(function ($q) use ($user) {
            $q->where('entity_type', User::class)->where('entity_id', $user->id);
            if ($user->entity_type === Employee::class && $user->entity_id) {
                $q->orWhere(function ($eq) use ($user) {
                    $eq->where('entity_type', Employee::class)->where('entity_id', $user->entity_id);
                });
            }
        })->first();

        if ($userSeatSubscription) {
            if ($userSeatSubscription->status === Subscription::STATUS_PENDING) {
                $partner = $user->partner;
                // Only block if partner is NOT a host
                if ($partner && (int) ($partner->is_host ?? 0) === 0) {
                    $partnerName = $partner->company_name ?? $partner->full_name ?? 'your partner';
                    return ApiResponse::loginError(__('auth.payment_not_completed', ['partner' => $partnerName]), 'email');
                }
            }

            if ($userSeatSubscription->subscription_end_date && Carbon::parse($userSeatSubscription->subscription_end_date)->isPast()) {
                $partner = $user->partner;
                // Only block if partner is NOT a host
                if ($partner && (int) ($partner->is_host ?? 0) === 0) {
                    $partnerName = $partner->company_name ?? $partner->full_name ?? 'your partner';
                    return ApiResponse::loginError(__('auth.subscription_expired', ['partner' => $partnerName]), 'email');
                }
            }
        }

        $isFreeTrail = false;
        $freeTrailEndDate = null;
        if ($user->entity) {
            $isFreeTrail = (bool) $user->entity->is_free_trail;
            $freeTrailEndDate = $user->entity->free_trail_end_date;
        }

        if ($isFreeTrail && $freeTrailEndDate && Carbon::parse($freeTrailEndDate)->isPast()) {
            $partner = $user->partner;
            $partnerName = $partner ? ($partner->company_name ?? $partner->full_name ?? 'your partner') : 'your partner';
            return ApiResponse::loginError(__('auth.free_trial_expired', ['partner' => $partnerName]), 'email');
        }

        $partner = $user->partner;

        // 1. Check User Account Status
        if (in_array($user->status, [User::STATUS['inactive']])) {
            $message = __('auth.user_inactive');
            return ApiResponse::loginError($message, 'email');
        }

        // 2. Check Associated Entity Status (Partner, Employee, Borrower)
        $checks = [];

        if ($user->entity_type == User::PARTNER) {
            $checks[] = [$partner?->status, Partner::STATUS_SUSPENDED, 'auth.partner_suspended', ['partner' => $partner?->company_name ?? $partner?->full_name ?? 'your partner']];
            $checks[] = [$partner?->status, Partner::STATUS_INACTIVE, 'auth.partner_inactive', ['partner' => $partner?->company_name ?? $partner?->full_name ?? 'your partner']];
        } elseif ($user->entity_type == User::EMPLOYEE) {
            $checks[] = [$user->employee?->job_status, Employee::STATUS_INACTIVE, 'auth.employee_inactive', []];
            $checks[] = [$user->employee?->job_status, Employee::STATUS_SUSPENDED, 'auth.employee_suspended', []];
        } elseif ($user->entity_type == User::BORROWER) {
            $checks[] = [$user->borrower?->status, Borrower::STATUS_INACTIVE, 'auth.borrower_inactive', []];
            $checks[] = [$user->borrower?->status, Borrower::STATUS_SUSPENDED, 'auth.borrower_suspended', []];
        }

        foreach ($checks as [$value, $disallowed, $message, $params]) {
            if ($value == $disallowed) {
                return ApiResponse::loginError(__($message, $params ?? []), 'email');
            }
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
        $relations = [
            'roles' => function ($query) use ($user) {
                $query->where('partner_id', $user->partner_id);
            },
            'roles.permissions',
            // 'subscription_phases' table (and its Subscription::phases() relation) was
            // dropped in 2026_08_31_120000_drop_subscription_phases_and_payment_methods_tables
            // - the phase state it held now lives directly on the subscription row
            // (current_phase, installments_paid, etc.), so there is nothing left to eager-load here.
            'partner.subscription',
        ];

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
