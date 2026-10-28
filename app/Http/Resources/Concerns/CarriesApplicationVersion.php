<?php

namespace App\Http\Resources\Concerns;

use App\Models\Borrower\FundingApplication;

/**
 * The funding application's version, for the wizard's step resources.
 *
 * The wizard sends it back with its next save as `expected_version`; a save
 * from a tab that loaded an older copy is refused instead of overwriting what
 * the other tab saved (R2 BUG-041). Null when there is no application yet.
 */
trait CarriesApplicationVersion
{
    protected function applicationVersion(): ?string
    {
        return $this->resource instanceof FundingApplication ? $this->resource->version() : null;
    }
}
