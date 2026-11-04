<?php

namespace App\Exceptions;

use DomainException;

/**
 * A delete was refused because funding sequences are still filed under the
 * funding group. Carries the blocked group (with how many sequences use it)
 * so the portal can name it.
 */
class FundingGroupInUseException extends DomainException
{
    /** @param array<int, array{id:int, name:string, count:int}> $blocked */
    public function __construct(string $message, public readonly array $blocked)
    {
        parent::__construct($message);
    }
}
