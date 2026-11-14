<?php

namespace App\Exceptions;

use DomainException;

/**
 * A contact delete was refused because the contact is a lead's Referral
 * Contact (R3-BUG-017). Carries what the portal needs to name them: for one
 * contact the leads (`leads`), for a bulk delete the contacts (`contacts`).
 */
class ContactInUseException extends DomainException
{
    /** @param array<string, array<int, array<string, mixed>>> $details */
    public function __construct(string $message, public readonly array $details)
    {
        parent::__construct($message);
    }
}
