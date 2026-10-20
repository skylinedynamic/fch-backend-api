<?php

namespace App\Services\Global\Account;

use App\Http\Controllers\Api\V1\Global\Account\EmailChangeLinkController;
use App\Mail\Account\EmailChangeConfirmationMail;
use App\Mail\Account\EmailChangedMail;
use App\Mail\Account\EmailChangeRequestedMail;
use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Notification\Notifier;
use App\Services\Global\Common\PortalUrlResolver;
use DateTimeInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Changing the email someone signs in with, for every kind of account
 * (partner, client, employee, plain user) — they all sign in through users.
 *
 * A change is held as pending until it's confirmed from the new address, so a
 * mistyped address can't lock anyone out. Confirming needs the account's
 * password as well as the link, so a stranger who receives a mistyped link
 * can't take the account. The old address is told when a change is requested
 * (with a link to cancel it) and again once it's confirmed (with a link to
 * undo it). Confirming switches the email, carries it to the record behind the
 * login, and signs the account out everywhere; the password is kept.
 */
class EmailChangeService
{
    public const LINK_LIFETIME_HOURS = 24;

    /** How long the old address can undo a confirmed change. */
    public const REVERT_LIFETIME_DAYS = 7;

    /** Wrong passwords allowed on the confirmation page before it's locked for a while. */
    public const MAX_PASSWORD_ATTEMPTS = 5;
    public const PASSWORD_LOCK_MINUTES = 15;

    /**
     * Start a change. Asking for the account's current email withdraws any
     * pending change instead, which is how an edit form "undoes" one.
     *
     * @throws ValidationException when another account already uses the email
     */
    public function request(User $user, string $newEmail): void
    {
        $newEmail = trim($newEmail);

        if ($this->sameEmail($newEmail, $user->email)) {
            $this->cancel($user);
            return;
        }

        $this->ensureAvailable($newEmail, $user);

        $user->forceFill([
            'pending_email' => $newEmail,
            'pending_email_requested_at' => now(),
        ])->save();

        // Two emails, sent back to back. A rate-limited mail account ("550 Too
        // many emails per second", e.g. Mailtrap's free tier) refuses the second
        // one, and a refused mail isn't retried — so the sends are spaced out.
        // The notice goes first because it can't be sent again, whereas the
        // confirmation has a Resend button if it's ever the one that's lost.
        $this->send('auth.email_change_requested', $user->email, new EmailChangeRequestedMail($user, $newEmail, $this->cancelUrl($user)));
        $this->pauseBetweenSends();
        $this->sendConfirmation($user);
    }

    /**
     * For a save that may carry a new sign-in email. When the record has a
     * login and the email was edited, the email is taken out of $data — so the
     * save leaves both the login and the record's own email alone — and
     * returned, to be passed to request() once the save has committed.
     *
     * $shownEmail is the email the edit form displayed. An untouched field is
     * therefore never a change, even for a record whose email had drifted from
     * its login's, and resubmitting it doesn't cancel a pending change.
     * Records without a login keep the email in $data and change it directly —
     * there's no sign-in to protect.
     *
     * Checked for availability here, before anything is written, so a clash
     * fails the whole save instead of half of it.
     *
     * @throws ValidationException
     */
    public function takeFrom(array &$data, ?User $user, ?string $shownEmail, string $key = 'email'): ?string
    {
        if (!$user || !array_key_exists($key, $data)) {
            return null;
        }

        $submitted = trim((string) $data[$key]);
        unset($data[$key]);

        if ($submitted === '' || $this->sameEmail($submitted, $shownEmail)) {
            return null;
        }

        $this->ensureAvailable($submitted, $user);

        return $submitted;
    }

    /**
     * @throws ValidationException when another account already uses the email
     */
    public function ensureAvailable(string $email, User $user): void
    {
        if (!$this->isAvailable($email, $user)) {
            throw ValidationException::withMessages(['email' => __('email_change.taken')]);
        }
    }

    public function resend(User $user): void
    {
        if (!$user->pending_email) {
            return;
        }

        $user->forceFill(['pending_email_requested_at' => now()])->save();
        $this->sendConfirmation($user);
    }

    /**
     * Withdraw a pending change. Its link stops working, because a link only
     * confirms the exact email that is pending when it's followed.
     */
    public function cancel(User $user): void
    {
        if ($user->pending_email === null) {
            return;
        }

        $user->forceFill(['pending_email' => null, 'pending_email_requested_at' => null])->save();
    }

    /**
     * Whether $hash is for the change that is pending now — true for a
     * confirmation or cancel link of the latest request, false once it's been
     * confirmed, cancelled or replaced. Link signatures (and so expiry) are
     * checked by the caller.
     */
    public function isPending(User $user, string $hash): bool
    {
        $pending = $user->pending_email;

        return $pending && hash_equals($this->hashFor($pending), $hash);
    }

    /**
     * Complete a change from its confirmation link. The link alone isn't
     * enough: a mistyped address puts it in a stranger's inbox, so the
     * account's current password is asked for too — and wrong guesses are
     * limited per account.
     */
    public function confirm(User $user, string $hash, string $password): EmailChangeResult
    {
        if (!$this->isPending($user, $hash)) {
            return EmailChangeResult::Invalid;
        }

        $attemptsKey = $this->passwordAttemptsKey($user);
        if (RateLimiter::tooManyAttempts($attemptsKey, self::MAX_PASSWORD_ATTEMPTS)) {
            return EmailChangeResult::TooManyAttempts;
        }

        if (!Hash::check($password, $user->password)) {
            RateLimiter::hit($attemptsKey, self::PASSWORD_LOCK_MINUTES * 60);
            return EmailChangeResult::WrongPassword;
        }
        RateLimiter::clear($attemptsKey);

        $pending = $user->pending_email;
        $oldEmail = $user->email;

        if (!$this->isAvailable($pending, $user)) {
            $this->cancel($user);
            return EmailChangeResult::Taken;
        }

        DB::transaction(function () use ($user, $pending, $oldEmail) {
            $user->forceFill([
                'email' => $pending,
                'email_verified_at' => now(),
                'pending_email' => null,
                'pending_email_requested_at' => null,
                'previous_email' => $oldEmail,
                'email_changed_at' => now(),
            ])->save();

            $this->setEntityEmail($user, $pending);
            $this->signOutEverywhere($user);
        });

        // The way back, should the new address not have been theirs.
        $this->send('auth.email_changed', $oldEmail, new EmailChangedMail($user, $oldEmail, $this->revertUrl($user), self::REVERT_LIFETIME_DAYS));

        return EmailChangeResult::Changed;
    }

    /** Minutes until confirming is allowed again after too many wrong passwords. */
    public function passwordLockMinutes(User $user): int
    {
        return (int) ceil(RateLimiter::availableIn($this->passwordAttemptsKey($user)) / 60);
    }

    /** Withdraw a change from the cancel link sent to the old address. */
    public function cancelFromLink(User $user, string $hash): EmailChangeResult
    {
        if (!$this->isPending($user, $hash)) {
            return EmailChangeResult::Invalid;
        }

        $this->cancel($user);

        return EmailChangeResult::Cancelled;
    }

    /**
     * Whether $hash is for the account's last confirmed change, and that change
     * is still in effect. An undo link is used up once followed, and dies when
     * the email is changed again.
     */
    public function isRevertible(User $user, string $hash): bool
    {
        $previous = $user->previous_email;

        return $previous
            && $user->email_changed_at
            && $user->email_changed_at->gt(now()->subDays(self::REVERT_LIFETIME_DAYS))
            && hash_equals($this->revertHashFor($previous, $user->email), $hash);
    }

    /**
     * Undo a confirmed change from the link sent to the old address. Whoever
     * confirmed it may have reset the password from the new inbox and may be
     * signed in, so the password is replaced with one nobody knows, their
     * reset links are dropped and every session ends; the owner sets a new
     * password from the old address with "Forgot password".
     */
    public function revert(User $user, string $hash): EmailChangeResult
    {
        if (!$this->isRevertible($user, $hash)) {
            return EmailChangeResult::Invalid;
        }

        $previous = $user->previous_email;
        $current = $user->email;

        if (!$this->isAvailable($previous, $user)) {
            return EmailChangeResult::Taken;
        }

        DB::transaction(function () use ($user, $previous, $current) {
            $user->forceFill([
                'email' => $previous,
                'email_verified_at' => now(),
                'pending_email' => null,
                'pending_email_requested_at' => null,
                'previous_email' => null,
                'email_changed_at' => null,
                'password' => Hash::make(Str::random(64)),
            ])->save();

            $this->setEntityEmail($user, $previous);

            DB::table('password_reset_tokens')->whereIn('email', [$current, $previous])->delete();
            $this->signOutEverywhere($user);
        });

        return EmailChangeResult::Reverted;
    }

    public function confirmationUrl(User $user): string
    {
        return $this->portalLink($user, EmailChangeLinkController::ACTION_CONFIRM, now()->addHours(self::LINK_LIFETIME_HOURS), $this->hashFor((string) $user->pending_email));
    }

    public function cancelUrl(User $user): string
    {
        return $this->portalLink($user, EmailChangeLinkController::ACTION_CANCEL, now()->addHours(self::LINK_LIFETIME_HOURS), $this->hashFor((string) $user->pending_email));
    }

    public function revertUrl(User $user): string
    {
        return $this->portalLink($user, EmailChangeLinkController::ACTION_UNDO, now()->addDays(self::REVERT_LIFETIME_DAYS), $this->revertHashFor((string) $user->previous_email, (string) $user->email));
    }

    /** The sign-in page of the portal this account uses. */
    public function loginUrl(User $user): string
    {
        return app(PortalUrlResolver::class)->url($user, 'login');
    }

    /**
     * A link to the account's own portal page for $action. It carries the
     * expires and signature of the matching API endpoint, signed over the
     * relative URL, which the page passes along when it calls the API.
     */
    private function portalLink(User $user, string $action, DateTimeInterface $expires, string $hash): string
    {
        $signed = URL::temporarySignedRoute("email-change.link.{$action}", $expires, ['user' => $user->id, 'hash' => $hash], absolute: false);

        return app(PortalUrlResolver::class)->url($user, "email-change/{$action}/{$user->id}/{$hash}") . '?' . parse_url($signed, PHP_URL_QUERY);
    }

    /**
     * Free unless another active login uses it. A deleted login no longer holds
     * its email: users.email has had no unique index since 2026_03_06_114733.
     */
    private function isAvailable(string $email, User $user): bool
    {
        return !User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->whereKeyNot($user->getKey())
            ->exists();
    }

    /** The record behind the login shows the same email. */
    private function setEntityEmail(User $user, string $email): void
    {
        $entity = $user->entity;

        if ($entity instanceof Employee || $entity instanceof Partner || $entity instanceof Borrower) {
            $entity->forceFill(['email' => $email])->save();
        }
    }

    private function passwordAttemptsKey(User $user): string
    {
        return 'email-change-confirm:' . $user->id;
    }

    private function signOutEverywhere(User $user): void
    {
        $tokenIds = DB::table('oauth_access_tokens')->where('user_id', $user->id)->pluck('id');

        DB::table('oauth_access_tokens')->whereIn('id', $tokenIds)->update(['revoked' => true]);
        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
    }

    private function sendConfirmation(User $user): void
    {
        $this->send(
            'auth.email_change_confirmation',
            $user->pending_email,
            new EmailChangeConfirmationMail($user, $this->confirmationUrl($user), self::LINK_LIFETIME_HOURS)
        );
    }

    /**
     * These mails aren't queued, so a refusal from the mail server — usually a
     * rate limit — is retried here, after each of the configured waits. A mail
     * failure mustn't undo the request: the change stays pending and can be
     * resent.
     */
    private function send(string $type, string $to, Mailable $mail): void
    {
        $waits = (array) config('mail.send_retry_delays_ms', []);
        $mail->to($to);

        while (true) {
            try {
                app(Notifier::class)->send($type, mail: $mail);
                return;
            } catch (TransportExceptionInterface $e) {
                if ($waits === []) {
                    Log::error('Email change mail failed', ['to' => $to, 'mail' => $mail::class, 'error' => $e->getMessage()]);
                    return;
                }
                $this->sleepMs((int) array_shift($waits));
            } catch (Throwable $e) {
                Log::error('Email change mail failed', ['to' => $to, 'mail' => $mail::class, 'error' => $e->getMessage()]);
                return;
            }
        }
    }

    private function pauseBetweenSends(): void
    {
        $this->sleepMs((int) config('mail.send_gap_ms'));
    }

    private function sleepMs(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private function hashFor(string $email): string
    {
        return sha1(mb_strtolower($email));
    }

    private function revertHashFor(string $previous, string $current): string
    {
        return sha1(mb_strtolower($previous) . '|' . mb_strtolower($current));
    }

    private function sameEmail(?string $a, ?string $b): bool
    {
        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }
}
