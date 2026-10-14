<?php

namespace App\Http\Controllers\Api\V1\Borrower\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Global\Auth\LoginRequest;
use App\Models\User\User;
use App\Services\Global\Auth\AuthService;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{

    /**
     * Borrower Login.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $authService = new AuthService();
        $credentials = $request->validated();
        return $authService->login($credentials, $request, User::BORROWER);
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
