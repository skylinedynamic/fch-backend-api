<?php

namespace App\Exceptions;

use DomainException;

/**
 * A signature was refused because required agreement fields were left empty,
 * or a signature was not a plain image. Carries the field keys so the portal
 * can point at them.
 */
class AgreementFieldsInvalidException extends DomainException
{
    /** @param list<string> $fields */
    public function __construct(string $message, public readonly array $fields)
    {
        parent::__construct($message);
    }
}
