<?php

namespace Tests\Feature\Website;

use App\Http\Controllers\Api\V1\Global\Website\PreQualification\PreQualificationController;
use App\Models\Borrower\Borrower;
use App\Models\Common\AppConfiguration;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CreatesFundingTypes;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-090: a website pre-qualification became a lead without its entity
 * type, industry or country - the website lead kept only a fixed few fields -
 * so the borrower converted from it had them empty, and the convert dialog
 * asked for the country (and, on choosing one, the state again). The lead now
 * keeps them, in the portal's own options, and the borrower gets them.
 */
#[CoversClass(PreQualificationController::class)]
class WebsitePrequalLeadConversionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;
    use CreatesFundingTypes;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        // Purpose of Funding is a lender product category (request 2026-11-05).
        $this->createFundingTypes(["Equipment Financing"]);
        $this->host = $this->makeHostPartner();
        AppConfiguration::create(['partner_id' => $this->host->id, 'module' => AppConfiguration::MODULE_BRANDING, 'key' => 'site_name', 'value' => 'Host']);
        $this->withoutMiddleware(\App\Http\Middleware\VerifyRecaptcha::class);
    }

    private function prequal(array $overrides = []): Lead
    {
        $this->postJson('/api/v1/website/pre-qualification', array_merge([
            'first_name' => 'Wendy', 'last_name' => 'Web', 'email' => 'wendy-' . uniqid() . '@example.test', 'phone_number' => '(555) 234-5678',
            'state_of_incorporation' => 'Florida', 'business_name' => 'QA-TEST Web Prequal Co',
            'industry_type' => 'Beauty, Salon & Spa Services', 'business_structure' => 'S-Corporation',
            'years_in_business' => '1-2 years', 'monthly_revenue' => 20000, 'annual_revenue' => 240000,
            'funding_requested_amount' => 50000, 'funding_urgency' => 'Within 30 days', 'purpose_of_funding' => 'Equipment Financing',
            'personal_credit_score' => '700-749', 'estimated_business_credit_score' => '50-74', 'existing_business_debt' => false,
        ], $overrides))->assertOk();

        return Lead::latest('id')->firstOrFail();
    }

    #[Test]
    public function the_lead_keeps_the_business_details_in_the_portals_options(): void
    {
        $lead = $this->prequal();

        $this->assertSame('QA-TEST Web Prequal Co', $lead->company_name);
        $this->assertSame('S-Corporation', $lead->entity_type);
        $this->assertSame('Personal Services (Beauty, Fitness, Wellness)', $lead->industry_type);
        $this->assertSame('Florida', $lead->state);
        $this->assertSame(Lead::DEFAULT_COUNTRY, $lead->country);
    }

    #[Test]
    public function an_answer_with_no_portal_match_is_kept_as_other_with_its_words(): void
    {
        $lead = $this->prequal(['industry_type' => 'Cannabis / CBD Industry', 'business_structure' => 'PLLC']);

        $this->assertSame('Other', $lead->industry_type);
        $this->assertSame('Cannabis / CBD Industry', $lead->industry_type_note);
        $this->assertSame('Other', $lead->entity_type);
        $this->assertSame('PLLC', $lead->entity_type_note);
    }

    #[Test]
    public function the_converted_borrower_gets_the_company_entity_industry_and_location(): void
    {
        $lead = $this->prequal();
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        // Country is on the lead, so the convert dialog has nothing to ask.
        $this->postJson("/api/v1/common/leads/{$lead->id}/convert", ['is_free_trail' => true])->assertOk();

        $borrower = Borrower::latest('id')->firstOrFail();
        $this->assertSame('QA-TEST Web Prequal Co', $borrower->business_name);
        $this->assertSame('S-Corporation', $borrower->business_entity_type);
        $this->assertSame('Personal Services (Beauty, Fitness, Wellness)', $borrower->business_industry_type);
        $this->assertSame('Florida', $borrower->business_state);
        $this->assertSame(Lead::DEFAULT_COUNTRY, $borrower->business_country);
    }
}
