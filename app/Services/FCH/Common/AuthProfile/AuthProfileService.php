<?php

namespace App\Services\FCH\Common\AuthProfile;

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
                'current_password' => [__('profile.current_password_incorrect')]
            ], __('profile.validation_failed'));
        }

        $user->update([
            'password' => Hash::make($data['new_password']),
        ]);

        // 📧 Send Password Changed Notification
        try {
            $mailData = [
                'subject'           => 'Security Alert: Password Changed - ' . config('app.name'),
                'user'              => $user,
                'notification_type' => 'security',
                'content' => [
                    'greeting' => 'Hello ' . ($user->name ?? 'User') . '!',
                    'lines' => [
                        'This is a confirmation that your account password was recently updated.',
                        'Below is your new login password:',
                    ],
                ],
                'data' => [
                    'code' => $data['new_password']
                ]
            ];

            \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Services\Global\Notification\Mail\GenericNotificationMail($mailData));
            \Illuminate\Support\Facades\Log::info('📧 Password change notification sent', ['user_id' => $user->id]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('📧 Password change notification failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return ApiResponse::success(null, __('profile.password_update_success'));
    }

    /**
     * Update user profile
     */
    public function updateProfile(User $user, array $data): JsonResponse
    {
        if (isset($data['first_name']) || isset($data['last_name'])) {
            $firstName = $data['first_name'] ?? '';
            $lastName = $data['last_name'] ?? '';
            $data['name'] = trim("{$firstName} {$lastName}");
            
            unset($data['first_name'], $data['last_name']);
        }

        $user->update($data);

        return ApiResponse::success($user, __('profile.profile_update_success'));
    }
}
