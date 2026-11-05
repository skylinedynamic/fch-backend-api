<?php

namespace Tests\Feature\PreQualification;

use App\Http\Requests\Api\V1\Borrower\PreQualification\FundingInfoRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesFundingTypes;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Request 2026-11-05: the borrower portal's Pre-Qualification > Purpose of
 * Funding is a lender product category (GET /common/funding-types) - an Active
 * one, as the funding application's funding types are - so the pre-
 * qualification's purpose is the category lenders are matched on.
 */
#[CoversClass(FundingInfoRequest::class)]
class BorrowerPreQualificationPurposeTest extends TestCase
{
    use CreatesFundingTypes;
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createFundingTypes(['Term Loan', 'Business Line of Credit', 'Other']);
        $this->actingAsUser($this->makeBorrowerUser($this->makeCertifiedPartner()));
    }

    #[Test]
    public function an_active_category_is_saved_as_the_purpose(): void
    {
        $this->save(['purpose_of_funding' => 'Business Line of Credit'])
            ->assertOk()
            ->assertJsonPath('data.purpose_of_funding', 'Business Line of Credit');
    }

    #[Test]
    public function a_purpose_that_is_not_an_active_category_is_refused(): void
    {
        foreach (['Working Capital', 'Line of Credit', 'business line of credit'] as $purpose) {
            $this->save(['purpose_of_funding' => $purpose])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['purpose_of_funding' => __('borrower_validation.purpose_of_funding_invalid')]);
        }
    }

    #[Test]
    public function the_other_category_still_needs_its_note(): void
    {
        $this->save(['purpose_of_funding' => 'Other'])->assertUnprocessable()->assertJsonValidationErrors('purpose_note');
        $this->save(['purpose_of_funding' => 'Other', 'purpose_note' => 'Trade show booth'])->assertOk();
    }

    private function save(array $changes)
    {
        return $this->postJson('/api/v1/borrower/pre-qualification/funding-info', array_merge([
            'funding_requested_amount' => 50000,
            'funding_urgency' => 'Within 30 days',
            'personal_credit_score' => array_key_first(config('prequalification_aue.personal_credit_bands', [])),
            'estimated_business_credit_score' => '50-79',
            'existing_business_debt' => false,
        ], $changes));
    }
}
