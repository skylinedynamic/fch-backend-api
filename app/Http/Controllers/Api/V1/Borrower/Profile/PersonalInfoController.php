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
use Illuminate\Validation\ValidationException;

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

        // Carries the borrower's full SSN, so no browser or proxy keeps a copy.
        return ApiResponse::success(new PersonalProfileResource($user), __('borrower.fetch_personal_profile'))
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Update Personal Info.
     */
    public function update(UpdatePersonalProfileRequest $request): JsonResponse
    {
        try {
            $user = Auth::user();
            // A new email isn't saved yet: it waits for confirmation from the
            // link sent to it, which the message tells the borrower.
            $requestedEmail = $this->profileService->updateProfile($user, $request->validated());
            $updatedUser = User::with('entity')->find($user->id);

            return ApiResponse::success(
                new PersonalProfileResource($updatedUser),
                $requestedEmail
                    ? __('email_change.requested', ['email' => $requestedEmail])
                    : __('borrower.update_personal_profile')
            );
        } catch (ValidationException $e) {
            // An email another account uses is a 422 on the field, not a failure.
            throw $e;
        } catch (\Exception $e) {
            return ApiResponse::error(__($e->getMessage()), 500);
        }
    }
}
