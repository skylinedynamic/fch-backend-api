<?php

namespace App\Support\EmbedForm;

use RuntimeException;

/**
 * Raised when a submission's email belongs to an existing Borrower and the
 * partner has not yet confirmed reusing them. Carries the details to show.
 */
class ExistingBorrowerConfirmationRequired extends RuntimeException
{
    public function __construct(public readonly array $borrower)
    {
        parent::__construct(__('embed_form.existing_borrower_confirmation_required'));
    }
}
