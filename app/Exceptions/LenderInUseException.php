<?php

namespace App\Exceptions;

use DomainException;

/**
 * A delete was refused because a selected lender is still in use (assigned to
 * a funding request, or in a funding sequence). Carries the blocked lenders so
 * the portal can name them.
 */
class LenderInUseException extends DomainException
{
    /** @param array<int, array{id:int, name:string}> $blocked */
    public function __construct(string $message, public readonly array $blocked)
    {
        parent::__construct($message);
    }
}
