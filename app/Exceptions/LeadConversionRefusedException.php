<?php

namespace App\Exceptions;

use DomainException;

/**
 * A lead conversion was refused for a reason the partner can act on: the lead
 * has already been converted (e.g. from another tab, BUG-057), its email
 * already belongs to a client or partner, or contact details are missing.
 * Answered with 422 and the message, never a 500.
 */
class LeadConversionRefusedException extends DomainException
{
}
