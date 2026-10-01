<?php

namespace App\Mail\Account;

use App\Models\User\User;
use App\Services\Global\Notification\Mail\GenericNotificationMail;

/**
 * Sent to the address being replaced, so a change nobody asked for doesn't go
 * unnoticed — it arrives before the change can take effect, with a link that
 * withdraws it.
 */
class EmailChangeRequestedMail extends GenericNotificationMail
{
    // EmailChangeService retries a refused send itself; it isn't queued.
    protected bool $rethrowTransportFailures = true;

    public function __construct(User $user, string $newEmail, public string $cancelUrl)
    {
        parent::__construct([
            'subject' => __('email_change.requested_subject'),
            'user' => AccountMailRecipient::for($user, (string) $user->email),
            'notification_type' => 'security',
            'data' => [],
            'content' => [
                'greeting' => __('email_change.greeting', ['name' => $user->name]),
                'lines' => [
                    __('email_change.requested_line1', ['old' => $user->email, 'new' => $newEmail]),
                    __('email_change.requested_line2', ['new' => $newEmail]),
                ],
                'action' => ['url' => $cancelUrl, 'text' => __('email_change.cancel_button'), 'variant' => 'danger'],
                'warning' => __('email_change.requested_warning', ['new' => $newEmail]),
            ],
        ], $user->partner_id);
    }
}
