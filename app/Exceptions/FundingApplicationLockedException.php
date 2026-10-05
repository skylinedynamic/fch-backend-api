<?php

namespace App\Exceptions;

use App\Models\Borrower\FundingApplication;
use Exception;

/**
 * Thrown when a borrower or partner tries to change a funding application that
 * has moved past their reach - it is with a lender, under contract, funded,
 * closed or denied (see config/funding_application.php `statuses.locked`).
 *
 * Carries the status it refused so the caller can tell the user *why* the
 * application is read-only rather than just that it is.
 */
class FundingApplicationLockedException extends Exception
{
    public function __construct(
        public readonly string $applicationStatus,
        public readonly ?int $applicationId = null,
        ?string $message = null
    ) {
        parent::__construct(
            $message ?? __('application.funding_application_locked', ['status' => $applicationStatus]),
            423
        );
    }

    public static function for(FundingApplication $application): self
    {
        return new self($application->status ?? '', $application->id);
    }

    /**
     * 423 Locked: the request was understood and the caller is allowed here in
     * general - this particular application just isn't theirs to change any more.
     */
    public function getStatusCode(): int
    {
        return 423;
    }
}
