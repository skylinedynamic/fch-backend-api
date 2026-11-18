<?php

namespace Tests\Feature\OwnerSummary;

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\FCH\Common\OwnerSummary\OwnerSummaryController;
use App\Models\Borrower\FundingApplication;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\OwnerSummary\OwnerSummaryExportPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Business Insights › Export PDF (GET /api/v1/common/owner-summary/export):
 * the summary report (the headline numbers on a page or two) or the details
 * report (every section, every stalled deal), for the period and payments the
 * screen shows. For the host's team holding export business insights (and
 * view business insights, like the screen itself).
 */
#[CoversClass(OwnerSummaryController::class)]
#[CoversClass(OwnerSummaryExportPdf::class)]
class OwnerSummaryExportTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const URL = '/api/v1/common/owner-summary/export';

    private Partner $host;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        $this->host = $this->makeHostPartner(['company_name' => 'Four Corner Funding']);
        $this->owner = $this->makePartnerAdmin($this->host, [Permission::ViewBusinessInsights->value, Permission::ExportBusinessInsights->value]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function export(User $user, array $query): TestResponse
    {
        $this->actingAsUser($user);

        return $this->withHeader('X-Timezone', 'America/New_York')->get(self::URL . '?' . http_build_query($query));
    }

    /** @return iterable<string, array{string}> */
    public static function types(): iterable
    {
        yield 'summary report' => [OwnerSummaryExportPdf::TYPE_SUMMARY];
        yield 'details report' => [OwnerSummaryExportPdf::TYPE_DETAILS];
    }

    #[Test]
    #[DataProvider('types')]
    public function the_owner_downloads_the_report_as_a_pdf(string $type): void
    {
        $response = $this->export($this->owner, ['type' => $type, 'period' => 'this_month'])->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame(
            "attachment; filename=business-insights-{$type}-2026-11-01-to-2026-11-30.pdf",
            $response->headers->get('Content-Disposition')
        );
    }

    #[Test]
    public function a_custom_range_is_named_after_its_dates(): void
    {
        $response = $this->export($this->owner, ['type' => 'summary', 'period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'])->assertOk();

        $this->assertStringContainsString('business-insights-summary-2026-10-01-to-2026-10-31.pdf', $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function the_report_type_is_required_and_must_be_known(): void
    {
        $this->actingAsUser($this->owner);

        $this->getJson(self::URL . '?period=this_month')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->getJson(self::URL . '?type=everything')->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    #[Test]
    public function the_period_is_checked_as_on_the_screen(): void
    {
        $this->actingAsUser($this->owner);

        $this->getJson(self::URL . '?type=summary&period=custom&from=2026-11-10&to=2026-11-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    #[Test]
    public function only_the_hosts_team_holding_export_business_insights_may_export(): void
    {
        $this->getJson(self::URL . '?type=summary')->assertUnauthorized();

        $refused = [
            $this->makeStaffUser($this->host),
            $this->makePartnerAdmin($this->host, [Permission::UpdateCommission->value]),
            // May see the screen, may not export it.
            $this->makePartnerAdmin($this->host, [Permission::ViewBusinessInsights->value]),
            $this->makePartnerAdmin($this->makeWhiteLabelPartner(), [Permission::ViewBusinessInsights->value, Permission::ExportBusinessInsights->value]),
        ];
        foreach ($refused as $user) {
            $this->actingAsUser($user);
            $this->getJson(self::URL . '?type=summary')->assertForbidden();
        }
    }

    #[Test]
    public function the_details_report_lists_every_stalled_deal_not_only_the_first_ten(): void
    {
        $borrower = $this->makeBorrower($this->host, ['business_name' => 'Stalled Biz']);
        foreach (range(1, 12) as $i) {
            DB::table('funding_applications')->insert([
                'application_number' => sprintf('FUND-STALL-%02d', $i),
                'borrower_id' => $borrower->id,
                'partner_id' => $this->host->id,
                'status' => FundingApplication::UNDERWRITING_REVIEW,
                'requested_amount' => 10000,
                'created_at' => now()->subDays(20 + $i),
                'updated_at' => now()->subDays(20 + $i),
            ]);
        }

        $pdf = app(OwnerSummaryExportPdf::class);
        $details = $pdf->report($this->owner, OwnerSummaryExportPdf::TYPE_DETAILS, \App\Services\FCH\Common\OwnerSummary\OwnerSummaryPeriod::make('this_month', 'America/New_York'), 'production');
        $summary = $pdf->report($this->owner, OwnerSummaryExportPdf::TYPE_SUMMARY, \App\Services\FCH\Common\OwnerSummary\OwnerSummaryPeriod::make('this_month', 'America/New_York'), 'production');

        $this->assertCount(12, $details['stalled_deals']);
        $this->assertCount(10, $summary['stalled_deals']);

        $html = view(OwnerSummaryExportPdf::VIEWS[OwnerSummaryExportPdf::TYPE_DETAILS], $pdf->data(OwnerSummaryExportPdf::TYPE_DETAILS, $details))->render();
        $this->assertStringContainsString('FUND-STALL-01', $html);
        $this->assertStringContainsString('FUND-STALL-12', $html);
    }
}
