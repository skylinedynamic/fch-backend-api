<?php

namespace App\Mail\Account;

use App\Models\User\User;

/**
 * Who an account email is for, as the email template sees it. Its footer reads
 * "This email was sent to {user.email}", which must be the address the email
 * actually went to — for a confirmation that's the new, still-pending address,
 * not the account's current one — so the recipient is given explicitly rather
 * than read off the User.
 */
final class AccountMailRecipient
{
    public static function for(User $user, string $sentTo): object
    {
        return (object) [
            'name' => $user->name,
            'email' => $sentTo,
            'partner_id' => $user->partner_id,
        ];
    }
}
