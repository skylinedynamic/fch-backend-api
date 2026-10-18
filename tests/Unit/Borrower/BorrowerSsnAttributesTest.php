<?php

namespace Tests\Unit\Borrower;

use App\Models\Borrower\Borrower;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Borrower's ssn / last_4_ssn attributes, both stored in ssn_encrypted.
 *
 * Defect: the last_4_ssn mutator always encrypted whatever four digits it was
 * given into ssn_encrypted, and its callers (the admin borrower form, last-4
 * change requests) send the last four back on every save - so saving anything
 * on those screens replaced the borrower's full SSN with its last four digits.
 */
class BorrowerSsnAttributesTest extends TestCase
{
    private function borrowerWith(?string $ssn): Borrower
    {
        $borrower = new Borrower();
        $borrower->ssn = $ssn;

        return $borrower;
    }

    #[Test]
    public function resending_the_same_last_four_keeps_the_full_ssn(): void
    {
        $borrower = $this->borrowerWith('123-45-6789');

        $borrower->last_4_ssn = '6789';

        $this->assertSame('123456789', $borrower->ssn);
    }

    #[Test]
    public function different_last_four_replace_the_ssn(): void
    {
        $borrower = $this->borrowerWith('123-45-6789');

        $borrower->last_4_ssn = '4321';

        $this->assertSame('4321', $borrower->ssn);
    }

    #[Test]
    public function a_blank_last_four_clears_the_ssn(): void
    {
        $borrower = $this->borrowerWith('123-45-6789');

        $borrower->last_4_ssn = '';

        $this->assertNull($borrower->ssn);
    }

    #[Test]
    public function last_four_are_stored_when_nothing_is_on_file(): void
    {
        $borrower = $this->borrowerWith(null);

        $borrower->last_4_ssn = '6789';

        $this->assertSame('6789', $borrower->ssn);
    }

    #[Test]
    public function only_nine_digits_count_as_a_full_ssn(): void
    {
        $this->assertTrue($this->borrowerWith('123-45-6789')->hasFullSsn());
        $this->assertFalse($this->borrowerWith('6789')->hasFullSsn());
        $this->assertFalse($this->borrowerWith(null)->hasFullSsn());
    }
}
