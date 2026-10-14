<?php

namespace App\Services\FCH\Common\AuthProfile;

use App\Services\FCH\Common\Notification\Notifier;
use App\Helpers\ApiResponse;
use App\Models\Borrower\Borrower;
use App\Models\Common\AppConfiguration;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Log;
use App\Services\Global\Notification\Mail\GenericNotificationMail;

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

        // A new password ends every other session; this one stays signed in.
        $user->revokeOtherTokens();

        // 📧 Send Password Changed Notification
        try {
            $partner = getConfigPartner();
            $module = AppConfiguration::MODULE_BRANDING;
            $siteName = AppConfiguration::get('site_name', $partner?->company_name ?? config('app.name'), $module, $partner?->id);

            $mailData = [
                'subject' => __('profile.password_changed_subject'),
                'user' => $user,
                'notification_type' => 'security',
                'content' => [
                    'greeting' => __('profile.greeting', ['name' => $user->name ?? __('profile.default_user')]),
                    'lines' => [
                        __('profile.password_changed_line1'),
                        __('profile.password_changed_line2'),
                        __('profile.password_changed_line3'),
                    ],
                ],
            ];

            try {
                app(Notifier::class)->send('user.password_changed', $user,
                    mail: (new GenericNotificationMail($mailData))->to($user->email));
                Log::info('📧 Password change notification sent', ['user_id' => $user->id]);
            } catch (\Exception $e) {
                Log::error('📧 Password change notification failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('📧 Password change notification failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return ApiResponse::success(null, __('profile.password_update_success'));
    }

    /**
     * Update user profile
     */
    public function updateProfile(User $user, array $data): JsonResponse
    {
        $firstName = $data['first_name'] ?? '';
        $lastName = $data['last_name'] ?? '';
        $fullName = trim("{$firstName} {$lastName}");

        // Update User table
        $user->update([
            'name' => $fullName,
            'phone_number' => $data['phone_number'] ?? $user->phone_number,
        ]);

        // Sync with related entity tables
        if ($user->entity_type === Partner::class) {
            $partner = $user->entity;
            if ($partner) {
                $partner->update(['full_name' => $fullName]);
            }
        } elseif ($user->entity_type === Employee::class) {
            $employee = $user->entity;
            if ($employee) {
                // An employee's whole name lives in first_name (last_name is legacy).
                $employee->update([
                    'first_name' => $fullName,
                    'phone_number_1' => $user->phone_number,
                ]);
            }
        }

        return ApiResponse::success($user->fresh(), __('profile.profile_update_success'));
    }
    /**
     * Update user profile picture
     */
    public function updateProfilePicture(User $user, array $data): JsonResponse
    {
        $imagePath = $data['image'];

        // Sync with related entity tables
        if ($user->entity_type && $user->entity_id) {
            $entity = $user->entity;
            if ($entity) {
                if ($user->entity_type == Partner::class) {
                    $imagePath = 'partners/' . $user->entity_id . '/' . ltrim($imagePath, '/');
                    $entity->update(['partner_image' => $imagePath]);
                } elseif ($user->entity_type == Employee::class) {
                    $imagePath = 'employees/' . $user->entity_id . '/' . ltrim($imagePath, '/');
                    $entity->update(['image' => $imagePath]);
                }
            }
        }

        // Update User table
        $user->update([
            'image' => $imagePath,
        ]);

        return ApiResponse::success(['image' => $user->fresh()->image], __('profile.profile_picture_update_success'));
    }
}
