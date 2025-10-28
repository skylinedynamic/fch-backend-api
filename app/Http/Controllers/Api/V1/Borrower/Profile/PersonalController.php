<?php

namespace App\Http\Controllers\Api\V1\Borrower\Profile;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\Profile\UpdatePersonalProfileRequest;
use App\Http\Resources\Borrower\Profile\PersonalProfileResource;
use App\Services\Borrower\Profile\ProfileService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 *  Borrower Profile
 */
class PersonalController extends Controller
{
    protected ProfileService $profileService;

    public function __construct(ProfileService $profileService)
    {
        $this->profileService = $profileService;
    }

    /**
     * Get Profile.
     */
    public function index(): JsonResponse
    {
        $user = auth()->user();
        return ApiResponse::success(new PersonalProfileResource($user), __('borrower.index.fetch_personal_profile'));
    }

    /**
     * Update Profile.
     */
    public function update(UpdatePersonalProfileRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();
        
            // Update profile using the service
            $updatedUser = $this->profileService->updateProfile($user, $request->validated());

            return ApiResponse::success(
                new PersonalProfileResource($updatedUser),
                __('borrower.index.update_personal_profile')
            );
        } catch (\Exception $e) {
            return ApiResponse::error('Failed to update profile. Please try again.', 500);
        }
    }
}
