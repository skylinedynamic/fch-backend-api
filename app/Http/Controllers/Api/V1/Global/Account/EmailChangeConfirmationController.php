<?php

namespace App\Http\Controllers\Api\V1\Global\Account;

use App\Http\Controllers\Controller;
use App\Mail\Traits\MailableBranding;
use App\Models\User\User;
use App\Services\Global\Account\EmailChangeResult;
use App\Services\Global\Account\EmailChangeService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Where the links in the email-change emails land: confirm (sent to the new
 * address), cancel (sent to the old address before confirmation) and undo
 * (sent to the old address after it).
 *
 * They're opened from an inbox, possibly on another device, so they can't rely
 * on a portal session — each link's signature is the authority. Signatures are
 * checked here rather than with the `signed` middleware so an expired or
 * tampered link gets a page that explains itself instead of a bare 403.
 *
 * Opening a link (GET) never changes anything — mail scanners and link
 * previews open links too. It shows a page whose form (POST, to the same
 * signed URL) does the work; confirming also asks for the account password.
 */
class EmailChangeConfirmationController extends Controller
{
    // The pages are shown in the partner's own branding, resolved the same way
    // the emails that link to them are.
    use MailableBranding;

    public function __construct(private readonly EmailChangeService $emailChange)
    {
    }

    public function showConfirm(Request $request, int $user, string $hash): Response
    {
        $account = $this->account($request, $user);

        if (!$account || !$this->emailChange->isPending($account, $hash)) {
            return $this->outcome($account, EmailChangeResult::Invalid);
        }

        return $this->confirmForm($request, $account);
    }

    public function confirm(Request $request, int $user, string $hash): Response
    {
        $account = $this->account($request, $user);
        $result = $account
            ? $this->emailChange->confirm($account, $hash, (string) $request->input('password'))
            : EmailChangeResult::Invalid;

        if ($result === EmailChangeResult::WrongPassword) {
            return $this->confirmForm($request, $account, __('email_change.wrong_password'));
        }

        return $this->outcome($account?->refresh(), $result);
    }

    public function showCancel(Request $request, int $user, string $hash): Response
    {
        $account = $this->account($request, $user);

        if (!$account || !$this->emailChange->isPending($account, $hash)) {
            return $this->outcome($account, EmailChangeResult::Invalid);
        }

        return $this->page($account, [
            'title' => __('email_change.cancel_title'),
            'body' => __('email_change.cancel_body', ['email' => $account->email, 'new' => $account->pending_email]),
            'form' => ['action' => $request->fullUrl(), 'button' => __('email_change.cancel_button'), 'password' => false],
        ]);
    }

    public function cancel(Request $request, int $user, string $hash): Response
    {
        $account = $this->account($request, $user);
        $result = $account ? $this->emailChange->cancelFromLink($account, $hash) : EmailChangeResult::Invalid;

        return $this->outcome($account?->refresh(), $result);
    }

    public function showRevert(Request $request, int $user, string $hash): Response
    {
        $account = $this->account($request, $user);

        if (!$account || !$this->emailChange->isRevertible($account, $hash)) {
            return $this->outcome($account, EmailChangeResult::Invalid);
        }

        return $this->page($account, [
            'title' => __('email_change.revert_title'),
            'body' => __('email_change.revert_body', ['old' => $account->previous_email]),
            'form' => ['action' => $request->fullUrl(), 'button' => __('email_change.revert_button'), 'password' => false],
        ]);
    }

    public function revert(Request $request, int $user, string $hash): Response
    {
        $account = $this->account($request, $user);
        $result = $account ? $this->emailChange->revert($account, $hash) : EmailChangeResult::Invalid;

        return $this->outcome($account?->refresh(), $result);
    }

    /** The account a link is for — null when its signature is bad or expired. */
    private function account(Request $request, int $user): ?User
    {
        return $request->hasValidSignature() ? User::find($user) : null;
    }

    private function confirmForm(Request $request, User $account, ?string $error = null): Response
    {
        return $this->page($account, [
            'title' => __('email_change.confirm_title'),
            'body' => __('email_change.confirm_body', ['email' => $account->pending_email]),
            'form' => ['action' => $request->fullUrl(), 'button' => __('email_change.confirm_button'), 'password' => true],
            'error' => $error,
        ]);
    }

    private function outcome(?User $account, EmailChangeResult $result): Response
    {
        [$title, $body, $tone] = match ($result) {
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

        return $this->page($account, [
            'title' => $title,
            'body' => $body,
            'tone' => $tone,
            'loginUrl' => $account ? $this->emailChange->loginUrl($account) : null,
        ]);
    }

    private function page(?User $account, array $content): Response
    {
        $branding = $this->getBrandingData($account?->partner_id);

        // Partner-configured and written into a style block, so only a plain
        // hex colour is let through.
        $hex = fn (?string $colour, string $fallback) => preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $colour) ? $colour : $fallback;

        return response()->view('auth.email-change', array_merge([
            'tone' => 'info',
            'form' => null,
            'error' => null,
            'loginUrl' => null,
            'siteName' => $branding['siteName'] ?? config('app.name'),
            'logoUrl' => $branding['logoUrl'] ?? null,
            // The same palette the portal's sign-in page is painted with.
            'brandPrimary' => $hex($branding['brandPrimary'] ?? null, '#405189'),
            'brandPrimaryInk' => $hex($branding['brandPrimaryInk'] ?? null, '#2b3a6b'),
            'brandOnPrimary' => $hex($branding['brandOnPrimary'] ?? null, '#FFFFFF'),
        ], $content));
    }
}
