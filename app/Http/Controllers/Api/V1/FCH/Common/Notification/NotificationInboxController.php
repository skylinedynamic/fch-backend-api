<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Notification;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Notification\GetNotificationsRequest;
use App\Http\Resources\FCH\Notification\NotificationResource;
use App\Services\FCH\Common\Notification\NotificationInboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app notification inbox (bell + notifications page) for the signed-in
 * user. Shared by the borrower and partner portals.
 */
class NotificationInboxController extends Controller
{
    public function __construct(private readonly NotificationInboxService $inbox)
    {
    }

    public function index(GetNotificationsRequest $request): JsonResponse
    {
        $user = $request->user();
        $notifications = $this->inbox->list($user, $request->validated());

        return ApiResponse::successWithPagination(
            NotificationResource::collection($notifications->getCollection())->resolve(),
            [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'unread_count' => $this->inbox->unreadCount($user),
            ],
            __('notification.inbox.fetched')
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'unread_count' => $this->inbox->unreadCount($request->user()),
        ], __('notification.inbox.fetched'));
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $notification = $this->inbox->markRead($user, $id);

        if (!$notification) {
            return ApiResponse::notFound(__('notification.inbox.not_found'));
        }

        return ApiResponse::success([
            'notification' => (new NotificationResource($notification))->resolve(),
            'unread_count' => $this->inbox->unreadCount($user),
        ], __('notification.inbox.marked_read'));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $this->inbox->markAllRead($request->user());

        return ApiResponse::success([
            'updated' => $updated,
            'unread_count' => 0,
        ], __('notification.inbox.marked_all_read'));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$this->inbox->delete($user, $id)) {
            return ApiResponse::notFound(__('notification.inbox.not_found'));
        }

        return ApiResponse::success([
            'unread_count' => $this->inbox->unreadCount($user),
        ], __('notification.inbox.deleted'));
    }
}
