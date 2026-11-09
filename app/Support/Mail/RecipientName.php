<?php

namespace App\Support\Mail;

use App\Models\Borrower\Borrower;
use App\Models\Lead\Lead;
use App\Models\User\User;

/**
 * The name to greet an email address by: the user with that address, else the
 * client's or the lead's first name. Null when no one in the system has it -
 * the email then says just "Hello,".
 */
final class RecipientName
{
    public static function for(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));
        if ($email === '') {
            return null;
        }

        $name = User::whereRaw('LOWER(email) = ?', [$email])->value('name')
            ?? Borrower::whereRaw('LOWER(email) = ?', [$email])->value('first_name')
            ?? Lead::whereRaw('LOWER(email) = ?', [$email])->value('first_name');

        $name = is_string($name) ? trim($name) : '';

        return $name === '' ? null : $name;
    }
}
