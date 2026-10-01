<?php

namespace App\Services\Global\Account;

enum EmailChangeResult: string
{
    case Changed = 'changed';

    /** Expired, tampered with, cancelled, used up, or replaced by a newer request. */
    case Invalid = 'invalid';

    /** Another account took the address while it was waiting (or before an undo). */
    case Taken = 'taken';

    /** The account password given to confirm was wrong. */
    case WrongPassword = 'wrong_password';

    /** Too many wrong passwords: confirming is paused for a while. */
    case TooManyAttempts = 'too_many_attempts';

    /** Withdrawn from the old address before it was confirmed. */
    case Cancelled = 'cancelled';

    /** Undone from the old address after it was confirmed. */
    case Reverted = 'reverted';
}
