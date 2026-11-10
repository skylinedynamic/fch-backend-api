<?php

namespace Tests\Unit\FundApplication;

use App\Models\Borrower\FundingApplication;
use App\Services\FCH\Common\Application\FundingRequestStepFields;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The parts of FundingRequestStepFields that need no database: a save with no
 * existing application, and the "does this save choose the sequence" checks
 * that are decided before the embed form lookup. The rest is covered with the
 * database in Integration/Application/FundingRequestStepFieldsTest.
 */
#[CoversClass(FundingRequestStepFields::class)]
class FundingRequestStepFieldsTest extends TestCase
{
    private FundingRequestStepFields $fields;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fields = new FundingRequestStepFields;
    }

    #[Test]
    public function a_new_application_is_saved_as_sent(): void
    {
        $data = ['preferred_funding_type' => 'Term Loan', 'funding_sequence_id' => 6, 'approved_funding' => 'Term Loans'];

        $this->assertSame($data, $this->fields->apply(null, $data));
        // Not saved yet: nothing to keep.
        $this->assertSame($data, $this->fields->apply(new FundingApplication, $data));
    }

    #[Test]
    public function only_an_existing_application_without_a_funding_request_can_choose(): void
    {
        $this->assertFalse($this->fields->choosesFundingSequence(null, null));
        $this->assertFalse($this->fields->choosesFundingSequence(new FundingApplication, null));

        $fromRequest = $this->existing(['funding_request_id' => 7]);
        $this->assertFalse($this->fields->choosesFundingSequence($fromRequest, null));

        // A save that names a funding request is attaching one, not choosing.
        $this->assertFalse($this->fields->choosesFundingSequence($this->existing(), 7));
    }

    #[Test]
    public function the_preferred_funding_type_held_is_the_applications_own(): void
    {
        $this->assertSame('Term Loan', $this->fields->lockedPreferredFundingType($this->existing(['preferred_funding_type' => 'Term Loan'])));
        // None, and no funding request to take one from.
        $this->assertNull($this->fields->lockedPreferredFundingType($this->existing(['preferred_funding_type' => ''])));
    }

    /** An application as loaded from the database, without touching one. */
    private function existing(array $attributes = []): FundingApplication
    {
        $application = (new FundingApplication)->newFromBuilder(array_merge(['id' => 11, 'funding_request_id' => null], $attributes));
        $application->exists = true;

        return $application;
    }
}
