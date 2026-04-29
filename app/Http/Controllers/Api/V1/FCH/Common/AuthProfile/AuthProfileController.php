<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\AuthProfile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\AuthProfile\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\FCH\Common\AuthProfile\UpdateProfileRequest;
use App\Http\Requests\Api\V1\FCH\Common\AuthProfile\UpdateProfilePictureRequest;
use App\Services\FCH\Common\AuthProfile\AuthProfileService;
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
     * Update user password.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = Auth::user();
        $validatedData = $request->validated();

        return $this->authProfileService->updatePassword($user, $validatedData);
    }

    /**
     * Update user profile.
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = Auth::user();
        $validatedData = $request->validated();

        return $this->authProfileService->updateProfile($user, $validatedData);
    }

    /**
     * Update user profile picture.
     */
    public function updateProfilePicture(UpdateProfilePictureRequest $request): JsonResponse
    {
        $user = Auth::user();
        $validatedData = $request->validated();

        return $this->authProfileService->updateProfilePicture($user, $validatedData);
    }
}
