<?php

namespace Tests\Unit\Borrower;

use App\Models\Borrower\Borrower;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E2E-015: the borrower's self-service profile form only ever sends the last 4
 * SSN digits, and ProfileService writes them through the legacy `last_4_ssn`
 * mutator. That mutator encrypted the 4 digits straight into `ssn_encrypted`,
 * replacing the full 9-digit SSN - so any "Save Changes" on Personal Info,
 * even with nothing changed, destroyed the stored SSN.
 */
class BorrowerLast4SsnKeepsFullSsnTest extends TestCase
{
    private function borrowerWithSsn(string $ssn): Borrower
    {
        $borrower = new Borrower();
        $borrower->ssn = $ssn;

        return $borrower;
    }

    #[Test]
    public function saving_the_same_last_four_keeps_the_full_ssn(): void
    {
        $borrower = $this->borrowerWithSsn('123456789');

        $borrower->last_4_ssn = '6789';

        $this->assertSame('123456789', $borrower->ssn);
        $this->assertSame('6789', $borrower->last_4_ssn);
    }

    #[Test]
    public function different_last_four_still_replaces_it(): void
    {
        $borrower = $this->borrowerWithSsn('123456789');

        $borrower->last_4_ssn = '4321';

        $this->assertSame('4321', $borrower->last_4_ssn);
    }

    #[Test]
    public function a_borrower_with_no_ssn_gets_the_four_digits(): void
    {
        $borrower = new Borrower();

        $borrower->last_4_ssn = '6789';

        $this->assertSame('6789', $borrower->last_4_ssn);
    }

    #[Test]
    public function blank_still_clears_it(): void
    {
        $borrower = $this->borrowerWithSsn('123456789');

        $borrower->last_4_ssn = '';

        $this->assertNull($borrower->ssn);
    }
}
