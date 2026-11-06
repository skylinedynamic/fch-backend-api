<?php

namespace App\Exceptions;

use DomainException;

/**
 * A delete was refused because a selected embedded-form application has been
 * converted: it is the record behind a client and a funding application.
 * Carries those applications so the admin panel can name them.
 */
class EmbedFormSubmissionConvertedException extends DomainException
{
    /** @param array<int, array{id:int, name:string}> $converted */
    public function __construct(string $message, public readonly array $converted)
    {
        parent::__construct($message);
    }
}
