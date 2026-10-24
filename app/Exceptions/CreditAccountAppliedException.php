<?php

namespace App\Exceptions;

use DomainException;

/**
 * A delete was refused because a selected credit account has borrower
 * applications (from Credit Accounts or through a Capital Access play). Carries
 * those cards so the admin panel can name them.
 */
class CreditAccountAppliedException extends DomainException
{
    /** @param array<int, array{id:int, name:string|null}> $applied */
    public function __construct(string $message, public readonly array $applied)
    {
        parent::__construct($message);
    }
}
