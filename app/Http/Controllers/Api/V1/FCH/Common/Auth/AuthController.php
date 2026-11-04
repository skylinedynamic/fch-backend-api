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
     *
     * Every email gets the same answer (BUG-068). It used to be 400 "We can't
     * find a user with that email address" for an unknown email, 403 for
     * another portal's user, 200 for a known one - and "Please wait before
     * retrying" only for a real account - so anyone could check which emails
     * have an account, and on which portal. The link is still only sent to a
     * user who can sign in on this portal.
     */
    public function forgot(ForgotPasswordRequest $request)
    {
        $user = User::where('email', $request->email)->first();
        $domain = $this->portalIsolationService->resolveDomain($request);

        if ($user && $this->portalIsolationService->isUserAllowedOnDomain($user, $domain)) {
            // Sent, throttled or anything else: the answer below is the same.
            Password::sendResetLink($request->validated());
        }

        return ApiResponse::success(null, __('auth.password_reset_link_maybe_sent'));
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