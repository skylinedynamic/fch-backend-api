<?php

namespace App\Exceptions;

use DomainException;

/**
 * A delete was refused because a selected blog category or tag is still on a
 * blog post. Carries the blocked ones so the portal can name them.
 */
class BlogTaxonomyInUseException extends DomainException
{
    /** @param array<int, array{id:int, name:string}> $blocked */
    public function __construct(string $message, public readonly array $blocked)
    {
        parent::__construct($message);
    }
}
