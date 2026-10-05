<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Account;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Account\ManageEmailChangeRequest;
use App\Models\User\User;
use App\Services\Global\Account\EmailChangeService;
use Illuminate\Http\JsonResponse;

/**
 * Resend or withdraw a pending sign-in email change from an edit screen.
 * (Starting one is part of saving the edit form; completing one is the
 * confirmation link.)
 */
class EmailChangeController extends Controller
{
    public function __construct(protected EmailChangeService $emailChange) {}

    public function resend(ManageEmailChangeRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        if (!$user) {
            return ApiResponse::forbidden(__('employee.unauthorized'));
        }

        if (!$user->pending_email) {
            return ApiResponse::error(__('email_change.nothing_pending'), 422);
        }

        $this->emailChange->resend($user);

        return ApiResponse::success(
            ['pending_email' => $user->pending_email],
            __('email_change.resent', ['email' => $user->pending_email])
        );
    }

    public function cancel(ManageEmailChangeRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        if (!$user) {
            return ApiResponse::forbidden(__('employee.unauthorized'));
        }

        $this->emailChange->cancel($user);

        return ApiResponse::success(['pending_email' => null], __('email_change.cancelled'));
    }

    /**
     * The login, if the caller may manage it: one in the caller's own partner,
     * or any login for the host, which manages every partner. Anything else —
     * including an id that doesn't exist — is forbidden, so ids from other
     * partners can't be probed.
     */
    private function resolveUser(ManageEmailChangeRequest $request): ?User
    {
        $target = User::find($request->integer('user_id'));
        $actor = $request->user();

        if (!$target) {
            return null;
        }

        $sameTenant = $target->partner_id !== null && $target->partner_id === $actor->partner_id;
        $isHost = (bool) $actor->partner?->is_host;

        return ($sameTenant || $isHost) ? $target : null;
    }
}
