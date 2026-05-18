<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Auth;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Global\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Global\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Global\Auth\ResetPasswordRequest;
use App\Models\User\User;
use App\Services\Global\Common\PortalIsolationService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use App\Services\Global\Auth\AuthService;

class AuthController extends Controller
{
    protected $authService;
    protected $portalIsolationService;

    public function __construct(
        AuthService $authService,
        PortalIsolationService $portalIsolationService
    ) {
        $this->authService = $authService;
        $this->portalIsolationService = $portalIsolationService;
    }

    /**
     * Login Api.
     */
    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();
        return $this->authService->login($credentials, $request);
    }

    /**
     * Forget Password
     */
    public function forgot(ForgotPasswordRequest $request)
    {
        $user = User::where('email', $request->email)->first();
        if ($user) {
            if (!$this->portalIsolationService->isUserAllowedOnDomain($user, $this->portalIsolationService->resolveDomain($request))) {
                return ApiResponse::error($this->portalIsolationService->getUnauthorizedMessage($user, $this->portalIsolationService->resolveDomain($request)), 403);
            }
        }

        $status = Password::sendResetLink($request->validated());

        if ($status === Password::RESET_LINK_SENT) {
            return ApiResponse::success(null, __($status));
        }

        return ApiResponse::error(__($status), 400);
    }

    /**
     * Reset Password
     */
    public function reset(ResetPasswordRequest $request)
    {
        $user = User::where('email', $request->email)->first();
        if ($user) {
            $domain = $this->portalIsolationService->resolveDomain($request);
            if (!$this->portalIsolationService->isUserAllowedOnDomain($user, $domain)) {
                return ApiResponse::error($this->portalIsolationService->getUnauthorizedMessage($user, $domain), 403);
            }
        }

        $status = Password::reset(
            $request->validated(),
            function ($user, $password) {
                $user->password = $password; // hashed via model cast
                $user->setRememberToken(Str::random(60));
                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return ApiResponse::success(null, __($status));
        }

        return ApiResponse::error(__($status), 400);
    }

    /**
     * Logout.
     */
    public function logout()
    {
        return $this->authService->logout();
    }
}