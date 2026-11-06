<?php

namespace App\Exceptions;

use DomainException;

/**
 * A borrower's delete was refused because the document is the only Accepted
 * copy of a type that an open funding request or application needs.
 */
class DocumentInUseException extends DomainException
{
    /** @param array<int, int> $fundingRequestIds the requests that need it */
    public function __construct(string $message, public readonly array $fundingRequestIds)
    {
        parent::__construct($message);
    }
}
