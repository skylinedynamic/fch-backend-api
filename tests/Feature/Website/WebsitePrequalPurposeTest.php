<?php

namespace Tests\Feature\Website;

use App\Http\Middleware\VerifyRecaptcha;
use App\Http\Requests\Api\V1\Global\Website\PreQualification\StorePreQualificationRequest;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Common\AppConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Request 2026-11-05 (final): Purpose of Funding is the purpose, as it was - the
 * public website's pre-qualification form offers its own purposes (Working
 * Capital, Equipment, Expansion, ...), not the lender product categories, and
 * the API takes them as before.
 */
#[CoversClass(StorePreQualificationRequest::class)]
class WebsitePrequalPurposeTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $host = $this->makeHostPartner();
        AppConfiguration::create(['partner_id' => $host->id, 'module' => AppConfiguration::MODULE_BRANDING, 'key' => 'site_name', 'value' => 'Host']);
        $this->withoutMiddleware(VerifyRecaptcha::class);
    }

    /** @return array<string, array{string}> the website form's purposes */
    public static function websitePurposes(): array
    {
        return [
            'Working Capital' => ['Working Capital'],
            'Equipment' => ['Equipment'],
            'Expansion' => ['Expansion'],
            'Real Estate' => ['Real Estate'],
            'Debt Refinance / Consolidation' => ['Debt Refinance / Consolidation'],
            'Business Acquisition' => ['Business Acquisition'],
            'Startup Funding' => ['Startup Funding'],
            'Other' => ['Other'],
        ];
    }

    #[Test]
    #[DataProvider('websitePurposes')]
    public function each_website_purpose_is_saved_as_it_was(string $purpose): void
    {
        $this->submit($purpose)->assertOk();

        $this->assertSame($purpose, PreQualificationRequest::latest('id')->value('purpose_of_funding'));
    }

    #[Test]
    public function a_purpose_is_still_required(): void
    {
        $this->submit('')->assertUnprocessable()->assertJsonValidationErrors('purpose_of_funding');
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
