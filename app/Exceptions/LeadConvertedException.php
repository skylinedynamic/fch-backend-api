<?php

namespace App\Exceptions;

use DomainException;

/**
 * A delete was refused because a selected lead is already converted to a
 * borrower or partner. Carries the converted leads so the portal can name them.
 */
class LeadConvertedException extends DomainException
{
    /** @param array<int, array{id:int, name:string}> $converted */
    public function __construct(string $message, public readonly array $converted)
    {
        parent::__construct($message);
    }
}
