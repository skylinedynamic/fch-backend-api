<?php

namespace Tests\Feature\PreQualification;

use App\Http\Requests\Api\V1\Borrower\PreQualification\FundingInfoRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Request 2026-11-05 (final): the borrower portal's Pre-Qualification >
 * Purpose of Funding is the purpose, as it was - the purposes list (Business
 * Expansion, Payroll, ...), not the lender product categories - and the API
 * takes it as before.
 */
#[CoversClass(FundingInfoRequest::class)]
class BorrowerPreQualificationPurposeTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsUser($this->makeBorrowerUser($this->makeCertifiedPartner()));
    }

    #[Test]
    public function a_purpose_from_the_list_is_saved_as_it_was(): void
    {
        foreach (['Business Expansion', 'Furniture, Fixtures & Equipment (FF&E)', 'Working Capital'] as $purpose) {
            $this->save(['purpose_of_funding' => $purpose])
                ->assertOk()
                ->assertJsonPath('data.purpose_of_funding', $purpose);
        }
    }

    #[Test]
    public function a_purpose_is_still_required(): void
    {
        $this->save(['purpose_of_funding' => ''])->assertUnprocessable()->assertJsonValidationErrors('purpose_of_funding');
    }

    #[Test]
    public function other_still_needs_its_note(): void
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
