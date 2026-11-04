<?php

namespace App\Exceptions;

use DomainException;

/**
 * A delete was refused because the lender product category is still used by
 * the underwriting logic of a funding sequence. Carries the blocked category
 * (with how many sequences use it) so the portal can name it.
 */
class LenderProductCategoryInUseException extends DomainException
{
    /** @param array<int, array{id:int, name:string, count:int}> $blocked */
    public function __construct(string $message, public readonly array $blocked)
    {
        parent::__construct($message);
    }
}
