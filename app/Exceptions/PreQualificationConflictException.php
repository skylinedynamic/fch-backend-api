<?php

namespace App\Exceptions;

use Exception;

/**
 * The borrower's own pre-qualification request exists but is no longer in the
 * state the action needs: a submit of a request already submitted, or a retry
 * of a review that is not Failed (or that another retry has just claimed).
 *
 * Refused with 409 and the reason, not 404: the request is there, it has moved
 * on (BUG-168). An id that is not the borrower's stays a 404.
 */
class PreQualificationConflictException extends Exception
{
    public function __construct(string $message)
    {
        parent::__construct($message, 409);
    }

    /** 409 Conflict: the request was understood, the record is past this action. */
    public function getStatusCode(): int
    {
        return 409;
    }
}
