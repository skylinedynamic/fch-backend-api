<?php

namespace App\Services\Borrower\Auth;

use App\Helpers\ApiResponse;
use App\Models\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class AuthProfileService
{
    /**
     * Update user password
     */
    public function updatePassword(User $user, array $data): JsonResponse
    {
        if (!Hash::check($data['current_password'], $user->password)) {
            return ApiResponse::validationError([
                'current_password' => [__('borrower_validation.current_password_incorrect')]
            ], __('borrower_validation.validation_failed'));
        }

        $user->update([
            'password' => Hash::make($data['new_password']),
        ]);

        return ApiResponse::success(null, __('borrower_validation.password_update_success'));
    }
}
