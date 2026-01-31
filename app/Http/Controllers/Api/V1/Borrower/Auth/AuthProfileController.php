<?php

namespace App\Http\Controllers\Api\V1\Borrower\Auth;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\Auth\UpdatePasswordRequest;
use App\Services\Borrower\Auth\AuthProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AuthProfileController extends Controller
{
    protected $authProfileService;

    public function __construct(AuthProfileService $authProfileService)
    {
        $this->authProfileService = $authProfileService;
    }

    /**
     * Update borrower password.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = Auth::user();
        $validatedData = $request->validated();

        return $this->authProfileService->updatePassword($user, $validatedData);
    }
}
