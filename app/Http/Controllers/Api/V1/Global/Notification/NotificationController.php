<?php

namespace App\Http\Controllers\Api\V1\Global\Notification;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Global\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Test Email
     */
    public function sendTestEmail(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponse::unauthorized(__('auth.unauthorized'));
        }

        // Reuse existing user object but override if custom email/body provided
        if ($request->filled('email')) {
            $user->email = $request->email;
            $user->first_name = 'Recipient';
        }

        $message = (string) $request->input('body', $request->input('message', 'This is a test email notification from ' . config('app.name') . '.'));

        try {
            $sent = $this->notificationService->sendTestNotification($user, 'email', $message);

            if ($sent) {
                return ApiResponse::success([], 'Test email sent');
            }

            return ApiResponse::error('Failed to send test email', 500);
        } catch (\Exception $e) {
            return ApiResponse::error('Failed to send test email', 500, ['error' => $e->getMessage()]);
        }
    }
}