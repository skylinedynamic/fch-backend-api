<?php

namespace App\Http\Controllers\Api\V1\Borrower\Profile;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Borrower\Profile\UpdatePersonalProfileRequest;
use App\Http\Resources\Borrower\Profile\PersonalProfileResource;
use App\Models\User\User;
use App\Services\Borrower\Profile\ProfileService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 *  Borrower Profile
 */
class PersonalInfoController extends Controller
{
    protected ProfileService $profileService;

    public function __construct(ProfileService $profileService)
    {
        $this->profileService = $profileService;
    }

    /**
     * Get Personal Info.
     */
    public function index(): JsonResponse
    {
        $user = User::with('entity')->where('id', Auth::id())->first();
        return ApiResponse::success(new PersonalProfileResource($user), __('borrower.fetch_personal_profile'));
    }

    /**
     * Update Personal Info.
     */
    public function update(UpdatePersonalProfileRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            // Update profile using the service
            $updatedUser = $this->profileService->updateProfile($user, $request->validated());
            return ApiResponse::success(
                new PersonalProfileResource($updatedUser),
                __('borrower.update_personal_profile')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(__($e->getMessage()), 500);
        }
    }
}
