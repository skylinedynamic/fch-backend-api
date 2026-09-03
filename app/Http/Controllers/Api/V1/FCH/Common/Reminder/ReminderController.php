<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Reminder;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Services\FCH\Common\Reminder\ReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\FCH\Reminder\ReminderResource;
use App\Http\Requests\Api\V1\FCH\Common\Reminder\GetRemindersRequest;
use App\Http\Requests\Api\V1\FCH\Common\Reminder\SnoozeReminderRequest;

class ReminderController extends Controller
{
    protected ReminderService $reminderService;

    public function __construct(ReminderService $reminderService)
    {
        $this->reminderService = $reminderService;
    }

    /**
     * Get all reminders for the currently authenticated user (paginated).
     */
    public function index(GetRemindersRequest $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponse::unauthorized(__('auth.unauthorized'));
        }

        $reminders = $this->reminderService->getReminders($user, $request->validated());

        return ApiResponse::success([
            'reminders' => ReminderResource::collection($reminders)->response()->getData(true)
        ], __('reminder.fetched_successfully'));
    }

    /**
     * Get all due reminders for the currently authenticated user.
     */
    public function getDue(): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponse::unauthorized(__('auth.unauthorized'));
        }

        $reminders = $this->reminderService->getDueReminders($user);

        return ApiResponse::success([
            'reminders' => ReminderResource::collection($reminders)->resolve()
        ], __('reminder.due_fetched_successfully'));
    }

    /**
     * Mark a reminder as dismissed/sent.
     */
    public function dismiss(Request $request, $id): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponse::unauthorized(__('auth.unauthorized'));
        }

        $reminder = $this->reminderService->dismissReminder($user, $id);

        if (!$reminder) {
            return ApiResponse::error(__('reminder.not_found'), 404);
        }

        return ApiResponse::success([], __('reminder.dismissed_successfully'));
    }

    /**
     * Mark multiple reminders as dismissed/sent.
     */
    public function bulkDismiss(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponse::unauthorized(__('auth.unauthorized'));
        }

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer'
        ]);

        $count = $this->reminderService->bulkDismissReminders($user, $request->ids);

        return ApiResponse::success(['count' => $count], __('reminder.dismissed_successfully'));
    }

    /**
     * Mark all pending reminders as dismissed.
     */
    public function dismissAll(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponse::unauthorized(__('auth.unauthorized'));
        }

        $count = $this->reminderService->dismissAllReminders($user);

        return ApiResponse::success(['count' => $count], __('reminder.dismissed_successfully'));
    }

    /**
     * Snooze a reminder.
     */
    public function snooze(SnoozeReminderRequest $request, $id): JsonResponse
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponse::unauthorized(__('auth.unauthorized'));
        }

        $reminder = $this->reminderService->snoozeReminder(
            $user,
            $id,
            $request->minutes,
            $request->reschedule_at,
            $request->lead_minutes
        );

        if (!$reminder) {
            return ApiResponse::error(__('reminder.not_found'), 404);
        }

        return ApiResponse::success([
            'reminder' => new ReminderResource($reminder)
        ], __('reminder.snoozed_successfully'));
    }

    /**
     * Download a reminder attachment by path.
     */
    public function downloadAttachment(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) {
            return ApiResponse::unauthorized(__('auth.unauthorized'));
        }

        $path = $request->input('path');
        if (!$path) {
            return ApiResponse::error('path is required', 422);
        }

        try {
            return $this->reminderService->downloadAttachment((int) $id, (string) $path);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }
}
