<?php

namespace App\Exceptions;

use Exception;

/**
 * A save or decision was made from a page showing an older copy of the record:
 * someone else (or another tab) changed it in the meantime. Applying it would
 * silently undo that change, so it is refused and the user is asked to reload.
 *
 * The page sends what it last saw - the record's version, or the status it was
 * showing - and the server compares that with what is stored. Sending nothing
 * skips the check, so older clients keep working.
 */
class StaleRecordException extends Exception
{
    public function __construct(string $message, public readonly ?string $currentVersion = null)
    {
        parent::__construct($message, 409);
    }

    /** 409 Conflict: the request was fine, the record has moved on since the page loaded. */
    public function getStatusCode(): int
    {
        return 409;
    }
}
