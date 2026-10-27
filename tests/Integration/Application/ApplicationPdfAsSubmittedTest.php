<?php

namespace Tests\Integration\Application;

use App\Models\Borrower\FundingApplication;
use App\Services\FCH\Common\Application\FundingApplicationDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Defect: BUG-094. The downloaded application PDF was rebuilt from the
 * borrower's live profile: after an address change was approved, the signed
 * application said 401 QA Biz Ave while the application itself (and the
 * screen) still said 400, and its time in business grew on every download.
 */
#[CoversClass(FundingApplicationDocumentService::class)]
class ApplicationPdfAsSubmittedTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_pdf_shows_the_application_as_submitted_not_the_profile_changed_since(): void
    {
        Carbon::setTestNow('2026-10-21 05:16:00');
        $application = $this->signedApplication();

        $data = FundingApplicationDocumentService::getBrandingData($application->fresh());

        $this->assertSame('400 QA Biz Ave', $data['businessAddress']);
        $this->assertSame('QA Biz Town', $data['businessCity']);
        $this->assertSame('QA Strange LLC', $data['businessName']);
    }

    #[Test]
    public function time_in_business_is_counted_to_the_day_it_was_signed(): void
    {
        Carbon::setTestNow('2026-10-21 05:16:00');
        $application = $this->signedApplication();

        $data = FundingApplicationDocumentService::getBrandingData($application->fresh());

        // Signed 19-Oct: 5 years 17 days, however late it is downloaded.
        $this->assertSame('5 Years 17 Days', $data['timeInBusinessCount']);
    }

    #[Test]
    public function the_profile_still_fills_a_field_the_application_never_had(): void
    {
        $application = $this->signedApplication(['business_phone_no' => null]);

        $data = FundingApplicationDocumentService::getBrandingData($application->fresh());

        $this->assertSame('(555) 010-0401', $data['businessPhone']);
    }

    private function signedApplication(array $overrides = []): FundingApplication
    {
        $partner = $this->makeHostPartner();
        // The profile after the approved address change.
        $borrower = $this->makeBorrower($partner, [
            'business_name' => 'QA Strange LLC (renamed)',
            'business_address' => '401 QA Biz Ave',
            'business_city' => 'New Town',
            'business_phone' => '(555) 010-0401',
            'business_start_date' => '2021-10-02',
        ]);

        $id = DB::table('funding_applications')->insertGetId(array_merge([
            'application_number' => 'FUND-0000086',
            'borrower_id' => $borrower->id,
            'partner_id' => $partner->id,
            'status' => FundingApplication::SUBMITTED,
            // As submitted and signed.
            'business_name' => 'QA Strange LLC',
            'business_address' => '400 QA Biz Ave',
            'business_city' => 'QA Biz Town',
            'business_phone_no' => '(555) 010-0400',
            'signature_data' => json_encode(['signed_at' => '2026-10-19T08:30:00+00:00']),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return FundingApplication::findOrFail($id);
    }
}
