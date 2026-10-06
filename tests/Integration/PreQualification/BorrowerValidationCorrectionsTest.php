<?php

namespace Tests\Integration\PreQualification;

use App\Http\Resources\Borrower\PreQualification\PreQualificationResource;
use App\Models\Borrower\PreQualificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a borrower is told when the validator agent blocks their submission.
 *
 * A finding carries two texts: user_message, which the agent wrote for the
 * borrower, and internal_message, which it wrote for FCH staff — plus
 * suggested_action, which is staff guidance. Only the first may cross to the
 * borrower, and it crosses by name, so a staff-only field added to the agent's
 * schema later cannot reach a borrower by being forgotten.
 */
class BorrowerValidationCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int, array<string, mixed>> $issues */
    private function corrections(array $issues): array
    {
        $application = new PreQualificationRequest();
        $application->validation_issues = $issues;

        return (new PreQualificationResource($application))->toArray(request())['validation_corrections'];
    }

    #[Test]
    public function the_borrower_is_shown_the_agents_own_wording_for_each_blocking_finding(): void
    {
        $corrections = $this->corrections([
            [
                'field' => 'monthly_revenue',
                'severity' => 'BLOCKING',
                'user_message' => 'Your monthly revenue and annual revenue do not match. Please check both figures.',
                'internal_message' => 'Annual revenue is 40x the stated monthly revenue; likely a data entry error.',
                'suggested_action' => 'Request bank statements before routing.',
            ],
            [
                'field' => 'business_start_date',
                'severity' => 'BLOCKING',
                'user_message' => 'The business start date is in the future.',
                'internal_message' => 'Start date fails the date_compare rule.',
            ],
        ]);

        $this->assertSame([
            ['field' => 'monthly_revenue', 'message' => 'Your monthly revenue and annual revenue do not match. Please check both figures.'],
            ['field' => 'business_start_date', 'message' => 'The business start date is in the future.'],
        ], $corrections);
    }

    #[Test]
    public function no_staff_only_text_reaches_the_borrower(): void
    {
        $corrections = $this->corrections([[
            'field' => 'monthly_revenue',
            'severity' => 'BLOCKING',
            'user_message' => 'Please check your revenue figures.',
            'internal_message' => 'Likely a data entry error; do not route to lenders yet.',
            'suggested_action' => 'Request bank statements before routing.',
            // A field a future version of the agent's schema might add. It is
            // not named in the whitelist, so it does not travel.
            'underwriter_note' => 'Flagged for the credit committee.',
        ]]);

        $serialized = json_encode($corrections);

        $this->assertStringNotContainsString('data entry error', $serialized);
        $this->assertStringNotContainsString('bank statements', $serialized);
        $this->assertStringNotContainsString('credit committee', $serialized);
        $this->assertSame(['field', 'message'], array_keys($corrections[0]));
    }

    #[Test]
    public function a_warning_is_not_presented_as_something_to_correct(): void
    {
        $corrections = $this->corrections([
            ['severity' => 'WARNING', 'user_message' => 'Your website could not be reached.'],
            ['severity' => 'INFO', 'user_message' => 'No business credit reported.'],
            ['severity' => 'BLOCKING', 'user_message' => 'The business start date is in the future.'],
        ]);

        $this->assertSame([['field' => null, 'message' => 'The business start date is in the future.']], $corrections);
    }

    #[Test]
    public function a_finding_the_agent_gave_no_borrower_wording_for_is_dropped_not_substituted(): void
    {
        // Showing the internal text instead would put underwriting language
        // written about the borrower in front of the borrower.
        $corrections = $this->corrections([[
            'field' => 'monthly_revenue',
            'severity' => 'BLOCKING',
            'user_message' => '   ',
            'internal_message' => 'Revenue inconsistent with the stated time in business.',
        ]]);

        $this->assertSame([], $corrections);
    }

    #[Test]
    public function an_application_the_validator_passed_has_nothing_to_correct(): void
    {
        $this->assertSame([], $this->corrections([]));
    }
}
