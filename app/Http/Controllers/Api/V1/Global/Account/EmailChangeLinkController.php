<?php

namespace App\Http\Controllers\Api\V1\Global\Account;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\User\User;
use App\Services\Global\Account\EmailChangeResult;
use App\Services\Global\Account\EmailChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The email-change links (confirm, cancel, undo) open on the account's portal;
 * the portal page reads and acts on a link through these endpoints, passing
 * the link's expires and signature along unchanged. They're signed over the
 * relative URL, so the portal's request to the API checks against them.
 *
 * Like the pages EmailChangeConfirmationController still serves for links
 * already sent: reading a link (GET) never changes anything, acting on it is a
 * POST, and confirming also asks for the account password. Every answer is a
 * 200 whose `state` says what happened, with the wording to show.
 */
class EmailChangeLinkController extends Controller
{
    public const ACTION_CONFIRM = 'confirm';
    public const ACTION_CANCEL = 'cancel';
    public const ACTION_UNDO = 'undo';

    private const STATE_READY = 'ready';

    public function __construct(private readonly EmailChangeService $emailChange)
    {
    }

    public function showConfirm(Request $request, int $user, string $hash): JsonResponse
    {
        $account = $this->account($request, $user);
        if (!$account || !$this->emailChange->isPending($account, $hash)) {
            return $this->outcome(self::ACTION_CONFIRM, $account, EmailChangeResult::Invalid);
        }

        return $this->ready(self::ACTION_CONFIRM, $account, __('email_change.confirm_title'), __('email_change.confirm_body', ['email' => $account->pending_email]), __('email_change.confirm_button'));
    }

    public function confirm(Request $request, int $user, string $hash): JsonResponse
    {
        $account = $this->account($request, $user);
        $result = $account
            ? $this->emailChange->confirm($account, $hash, (string) $request->input('password'))
            : EmailChangeResult::Invalid;

        return $this->outcome(self::ACTION_CONFIRM, $account?->refresh(), $result);
    }

    public function showCancel(Request $request, int $user, string $hash): JsonResponse
    {
        $account = $this->account($request, $user);
        if (!$account || !$this->emailChange->isPending($account, $hash)) {
            return $this->outcome(self::ACTION_CANCEL, $account, EmailChangeResult::Invalid);
        }

        return $this->ready(self::ACTION_CANCEL, $account, __('email_change.cancel_title'), __('email_change.cancel_body', ['email' => $account->email, 'new' => $account->pending_email]), __('email_change.cancel_button'));
    }

    public function cancel(Request $request, int $user, string $hash): JsonResponse
    {
        $account = $this->account($request, $user);
        $result = $account ? $this->emailChange->cancelFromLink($account, $hash) : EmailChangeResult::Invalid;

        return $this->outcome(self::ACTION_CANCEL, $account?->refresh(), $result);
    }

    public function showRevert(Request $request, int $user, string $hash): JsonResponse
    {
        $account = $this->account($request, $user);
        if (!$account || !$this->emailChange->isRevertible($account, $hash)) {
            return $this->outcome(self::ACTION_UNDO, $account, EmailChangeResult::Invalid);
        }

        return $this->ready(self::ACTION_UNDO, $account, __('email_change.revert_title'), __('email_change.revert_body', ['old' => $account->previous_email]), __('email_change.revert_button'));
    }

    public function revert(Request $request, int $user, string $hash): JsonResponse
    {
        $account = $this->account($request, $user);
        $result = $account ? $this->emailChange->revert($account, $hash) : EmailChangeResult::Invalid;

        return $this->outcome(self::ACTION_UNDO, $account?->refresh(), $result);
    }

    /** The account a link is for — null when its signature is bad or expired. */
    private function account(Request $request, int $user): ?User
    {
        return $request->hasValidSignature(absolute: false) ? User::find($user) : null;
    }

    private function ready(string $action, User $account, string $title, string $message, string $button): JsonResponse
    {
        return $this->respond($action, self::STATE_READY, [
            'title' => $title,
            'message' => $message,
            'tone' => 'info',
            'button' => $button,
            'needs_password' => $action === self::ACTION_CONFIRM,
        ]);
    }

    private function outcome(string $action, ?User $account, EmailChangeResult $result): JsonResponse
    {
        if ($result === EmailChangeResult::WrongPassword && $account) {
            return $this->respond($action, $result->value, [
                'title' => __('email_change.confirm_title'),
                'message' => __('email_change.wrong_password'),
                'tone' => 'danger',
                'button' => __('email_change.confirm_button'),
                'needs_password' => true,
            ]);
        }

        [$title, $message, $tone] = match ($result) {
            EmailChangeResult::Changed => [__('email_change.changed_title'), __('email_change.changed_body', ['email' => $account?->email]), 'success'],
            EmailChangeResult::Cancelled => [__('email_change.cancelled_title'), __('email_change.cancelled_body', ['email' => $account?->email]), 'success'],
            EmailChangeResult::Reverted => [__('email_change.reverted_title'), __('email_change.reverted_body', ['email' => $account?->email]), 'success'],
            EmailChangeResult::Taken => [__('email_change.taken_title'), __('email_change.taken_body'), 'warning'],
            EmailChangeResult::TooManyAttempts => [
                __('email_change.too_many_attempts_title'),
                __('email_change.too_many_attempts_body', ['minutes' => $account ? max(1, $this->emailChange->passwordLockMinutes($account)) : 1]),
                'warning',
            ],
            default => [__('email_change.invalid_title'), __('email_change.invalid_body'), 'warning'],
        };
        $state = $result === EmailChangeResult::WrongPassword ? EmailChangeResult::Invalid->value : $result->value;

        return $this->respond($action, $state, [
            'title' => $title,
            'message' => $message,
            'tone' => $tone,
            'login_url' => $account ? $this->emailChange->loginUrl($account) : null,
        ]);
    }

    private function respond(string $action, string $state, array $content): JsonResponse
    {
        return ApiResponse::success(array_merge([
            'action' => $action,
            'state' => $state,
            'button' => null,
            'needs_password' => false,
            'login_url' => null,
        ], $content), $content['title']);
    }
}
