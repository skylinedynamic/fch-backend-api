<?php

namespace Tests\Feature\Website;

use App\Http\Middleware\VerifyRecaptcha;
use App\Http\Requests\Api\V1\Global\Website\PreQualification\StorePreQualificationRequest;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Common\AppConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesFundingTypes;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Request 2026-11-05: the public website's Pre-Qualification > Purpose of
 * Funding offers the lender product categories (GET /common/funding-types), so
 * the API takes only an Active category - as the borrower portal's pre-
 * qualification does.
 */
#[CoversClass(StorePreQualificationRequest::class)]
class WebsitePrequalPurposeTest extends TestCase
{
    use CreatesFundingTypes;
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $host = $this->makeHostPartner();
        AppConfiguration::create(['partner_id' => $host->id, 'module' => AppConfiguration::MODULE_BRANDING, 'key' => 'site_name', 'value' => 'Host']);
        $this->withoutMiddleware(VerifyRecaptcha::class);
        $this->createFundingTypes(['Term Loan', 'Equipment Financing']);
    }

    #[Test]
    public function an_active_category_is_saved_as_the_purpose(): void
    {
        $this->submit('Equipment Financing')->assertOk();

        $this->assertSame('Equipment Financing', PreQualificationRequest::latest('id')->value('purpose_of_funding'));
    }

    #[Test]
    public function a_purpose_that_is_not_an_active_category_is_refused(): void
    {
        foreach (['Equipment', 'Working Capital', 'equipment financing'] as $purpose) {
            $this->submit($purpose)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['purpose_of_funding' => __('lead.purpose_of_funding_invalid')]);
        }

        $this->assertSame(0, PreQualificationRequest::count());
    }

    private function submit(string $purpose)
    {
        return $this->postJson('/api/v1/website/pre-qualification', [
            'first_name' => 'Wendy', 'last_name' => 'Web', 'email' => 'wendy-' . uniqid() . '@example.test', 'phone_number' => '(555) 234-5678',
            'state_of_incorporation' => 'Florida', 'business_name' => 'QA-TEST Web Prequal Co',
            'industry_type' => 'Beauty, Salon & Spa Services', 'business_structure' => 'S-Corporation',
            'years_in_business' => '1-2 years', 'monthly_revenue' => 20000, 'annual_revenue' => 240000,
            'funding_requested_amount' => 50000, 'funding_urgency' => 'Within 30 days', 'purpose_of_funding' => $purpose,
            'personal_credit_score' => '700-749', 'estimated_business_credit_score' => '50-74', 'existing_business_debt' => false,
        ]);
    }
}
