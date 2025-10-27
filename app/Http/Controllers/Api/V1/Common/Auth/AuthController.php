<?php

namespace App\Http\Controllers\Api\V1\Common\Auth;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use App\Services\Common\Auth\AuthService;

class AuthController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
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
}