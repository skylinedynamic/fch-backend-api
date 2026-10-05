<?php

namespace App\Mail\Account;

use App\Models\User\User;
use App\Services\Global\Notification\Mail\GenericNotificationMail;

/**
 * Sent to the new address: following its link is what completes the change.
 */
class EmailChangeConfirmationMail extends GenericNotificationMail
{
    // EmailChangeService retries a refused send itself; it isn't queued.
    protected bool $rethrowTransportFailures = true;

    public function __construct(User $user, public string $confirmationUrl, int $validForHours)
    {
        parent::__construct([
            'subject' => __('email_change.confirm_subject'),
            'user' => AccountMailRecipient::for($user, (string) $user->pending_email),
            'notification_type' => 'security',
            'data' => [],
            'content' => [
                'greeting' => __('email_change.greeting', ['name' => $user->name]),
                'lines' => [
                    __('email_change.confirm_line1', ['email' => $user->pending_email]),
                    __('email_change.confirm_line2'),
                ],
                'action' => ['url' => $confirmationUrl, 'text' => __('email_change.confirm_button')],
                'info' => __('email_change.confirm_expiry', ['hours' => $validForHours]),
            ],
        ], $user->partner_id);
    }
}
