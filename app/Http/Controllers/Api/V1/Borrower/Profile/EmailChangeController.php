<?php

namespace App\Http\Controllers\Api\V1\Borrower\Profile;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Global\Account\EmailChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Resend or withdraw the borrower's own pending sign-in email change from the
 * profile screen. (Starting one is saving the profile with a new email;
 * completing one is the confirmation link.) Always the signed-in login: there
 * is no user id to pass, so a borrower can't reach anyone else's change.
 */
class EmailChangeController extends Controller
{
    public function __construct(protected EmailChangeService $emailChange) {}

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->pending_email) {
            return ApiResponse::error(__('email_change.nothing_pending'), 422);
        }

        $this->emailChange->resend($user);

        return ApiResponse::success(
            ['pending_email' => $user->pending_email],
            __('email_change.resent', ['email' => $user->pending_email])
        );
    }

    public function cancel(Request $request): JsonResponse
    {
        $this->emailChange->cancel($request->user());

        return ApiResponse::success(['pending_email' => null], __('email_change.cancelled'));
    }
}
