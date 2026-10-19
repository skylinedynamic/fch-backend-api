<?php

namespace App\Exceptions;

use DomainException;

/**
 * A delete was refused because a selected funding sequence is used by a
 * funding request. Carries the blocked sequences so the portal can name them.
 */
class FundingSequenceInUseException extends DomainException
{
    /** @param array<int, array{id:int, name:string}> $blocked */
    public function __construct(string $message, public readonly array $blocked)
    {
        parent::__construct($message);
    }
}
