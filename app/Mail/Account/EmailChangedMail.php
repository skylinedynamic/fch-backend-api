<?php

namespace App\Mail\Account;

use App\Models\User\User;
use App\Services\Global\Notification\Mail\GenericNotificationMail;

/**
 * Sent to the old address once a change is confirmed, with a link that undoes
 * it — the way back if the new address was wrong and someone else confirmed it.
 */
class EmailChangedMail extends GenericNotificationMail
{
    // EmailChangeService retries a refused send itself; it isn't queued.
    protected bool $rethrowTransportFailures = true;

    public function __construct(User $user, string $oldEmail, public string $revertUrl, int $validForDays)
    {
        parent::__construct([
            'subject' => __('email_change.changed_subject'),
            'user' => AccountMailRecipient::for($user, $oldEmail),
            'notification_type' => 'security',
            'data' => [],
            'content' => [
                'greeting' => __('email_change.greeting', ['name' => $user->name]),
                'lines' => [
                    __('email_change.changed_line1', ['old' => $oldEmail, 'new' => $user->email]),
                ],
                'action' => ['url' => $revertUrl, 'text' => __('email_change.revert_button')],
                'warning' => __('email_change.changed_warning'),
                'info' => __('email_change.revert_expiry', ['days' => $validForDays]),
            ],
        ], $user->partner_id);
    }
}
