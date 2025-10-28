<?php

namespace App\Http\Controllers\Api\V1\Borrower\Auth;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\Auth\BorrowerRegistrationRequest;
use App\Http\Requests\Api\V1\Global\Auth\LoginRequest;
use App\Http\Resources\Global\Auth\UserResource;
use App\Models\User\User;
use App\Services\Borrower\Auth\BorrowerAuthService;
use App\Services\Global\Auth\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{

    /**
     * Borrower Registration.
     */
    public function register(BorrowerRegistrationRequest $request): JsonResponse
    {
        $borrowerAuthService = new BorrowerAuthService();
        try {
            // Validate signup ID first
            if (!$borrowerAuthService->validateSignupId($request->signup_id)) {
                return ApiResponse::error(__('borrower_validation.signup_id_invalid'),422);
            }

            // Register the borrower
            $result = $borrowerAuthService->registerBorrower($request->validated());

            // Return success response
             return ApiResponse::success([
                    'token' => $result['token'],
                    'user' => new UserResource($result['user']),
                ], 'Borrower registered successfully');

        } catch (\Exception $e) {
            return ApiResponse::error('Registration failed. Please try again.',500);
        }
    }

    /**
     * Borrower Login.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $authService = new AuthService();
        $credentials = $request->validated();
        return $authService->login($credentials, $request, User::USERTYPE['Borrower']);
    }


    /**
     * Logout.
     */
    public function logout(): JsonResponse
    {
        $authService = new AuthService();
        return $authService->logout();
    }
}
