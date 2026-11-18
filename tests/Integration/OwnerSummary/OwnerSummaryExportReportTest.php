<?php

namespace Tests\Integration\OwnerSummary;

use App\Services\FCH\Common\OwnerSummary\OwnerReportPresenter;
use App\Services\FCH\Common\OwnerSummary\OwnerSummaryExportPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * What the two Business Insights exports print. The summary report is the
 * headline view - KPIs, money, what needs attention, the two trends - on a
 * page or two. The details report adds a cover with its contents and every
 * section of the screen in full. Both carry the period in the screen's words.
 */
#[CoversClass(OwnerSummaryExportPdf::class)]
#[CoversClass(OwnerReportPresenter::class)]
class OwnerSummaryExportReportTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'UTC'));
        $this->makeHostPartner(['company_name' => 'Four Corner Funding']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function report(array $changes = []): array
    {
        return array_replace_recursive(require base_path('tests/Fixtures/owner_weekly_report.php'), [
            'period' => ['key' => 'this_month', 'from' => '2026-11-01', 'to' => '2026-11-15', 'previous_from' => '2026-10-01', 'previous_to' => '2026-10-15'],
        ], $changes);
    }

    private function html(string $type, ?array $report = null): string
    {
        $pdf = app(OwnerSummaryExportPdf::class);

        return view(OwnerSummaryExportPdf::VIEWS[$type], $pdf->data($type, $report ?? $this->report()))->render();
    }

    #[Test]
    public function both_reports_are_titled_and_dated_for_the_period_shown(): void
    {
        foreach ([OwnerSummaryExportPdf::TYPE_SUMMARY => 'Summary Report', OwnerSummaryExportPdf::TYPE_DETAILS => 'Details Report'] as $type => $label) {
            $html = $this->html($type);

            $this->assertStringContainsString('Business Insights', $html);
            $this->assertStringContainsString($label, $html);
            $this->assertStringContainsString('This month', $html);
            $this->assertStringContainsString('1 – 15 Nov 2026', $html);
            $this->assertStringContainsString('Production payments', $html);
        }
        $this->assertStringContainsString('Confidential', $this->html(OwnerSummaryExportPdf::TYPE_DETAILS));
    }

    /** The summary is short: the figures at a glance and where net earnings came from - nothing else. */
    #[Test]
    public function the_summary_report_keeps_to_the_headlines(): void
    {
        $html = $this->html(OwnerSummaryExportPdf::TYPE_SUMMARY);

        foreach (['At a Glance', 'Net Earnings', 'Funded Volume', 'Funded Deals', 'Outstanding', 'Payouts still due'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        foreach (['Needs Attention', 'Funded Volume By Month', 'Breakdown', 'Pipeline', 'Top Partners', 'FUND-000044', 'Contents', 'Definitions'] as $text) {
            $this->assertStringNotContainsString($text, $html, "{$text} is for the details report");
        }
    }

    /**
     * The summary follows the owner's mock: performance cards (net earnings
     * first, filled), receivables & payables, the network, then net earnings
     * by stream - each change as a badge with its direction.
     */
    #[Test]
    public function the_summary_report_has_the_cards_of_the_agreed_design(): void
    {
        $html = $this->html(OwnerSummaryExportPdf::TYPE_SUMMARY);

        foreach (['At a Glance', 'Receivables &amp; Payables', 'Network', 'Partners and clients on the platform'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringContainsString('class="card hero"', $html);
        $this->assertStringContainsString('▲ +30%', $html);
        $this->assertStringContainsString('▼ -22%', $html);
        $this->assertStringContainsString('12 invoices awaiting payment', $html);
        $this->assertMatchesRegularExpression('/22<\/b> active/', $html);
        $this->assertStringContainsString('+3', $html);
    }

    /**
     * BI-BUG-01: the report follows the period picked, so "Receivables &
     * Payables" says so ("Raised this month, still unpaid"), and its Payouts
     * still due card is the period's figure with its deal count - the one
     * Needs Attention shows (attention.payouts_due). Both reports share the card.
     */
    #[Test]
    public function the_receivables_payouts_card_follows_the_period_like_needs_attention(): void
    {
        foreach ([OwnerSummaryExportPdf::TYPE_SUMMARY, OwnerSummaryExportPdf::TYPE_DETAILS] as $type) {
            $html = $this->html($type);
            $this->assertStringNotContainsString('Open right now', $html, "{$type}: nothing claims to be as of now there");
            $start = strpos($html, 'Raised this month, still unpaid');
            $this->assertNotFalse($start, "{$type}: the Receivables & Payables section");
            $card = substr($html, $start, strpos($html, 'Network', $start) - $start);

            $this->assertStringContainsString('Payouts still due', $card);
            $this->assertStringContainsString('$31,000', $card, "{$type}: the Needs Attention amount");
            $this->assertStringContainsString('5 deals', $card, "{$type}: the deals it is owed on");
        }
    }

    /** Net earnings by stream, with the total collected, in place of the attention list. */
    #[Test]
    public function the_summary_report_shows_net_earnings_by_stream(): void
    {
        $html = $this->html(OwnerSummaryExportPdf::TYPE_SUMMARY);

        foreach (['Partner enrollment', 'Platform fees', 'User seats', 'Borrower fees', 'Total collected', '59 payments', '$14,300'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    #[Test]
    public function the_details_report_has_its_contents_and_every_section(): void
    {
        $html = $this->html(OwnerSummaryExportPdf::TYPE_DETAILS);

        $this->assertStringContainsString('Contents', $html);
        $sections = ['Net Earnings', 'Commissions', 'Needs Attention', 'Pipeline', 'Lead Sources', 'Funded Volume By Month', 'Partner Network', 'Top Partners', 'Stalled Deals', 'Definitions'];
        $this->assertSame([], array_values(array_filter($sections, fn (string $section) => !str_contains($html, $section))));
        $this->assertStringContainsString('FUND-000044', $html);
    }

    #[Test]
    public function the_wording_follows_the_period_not_last_week(): void
    {
        $month = $this->html(OwnerSummaryExportPdf::TYPE_DETAILS);
        $this->assertStringContainsString('New this month', $month);
        $this->assertStringNotContainsString('last week', strtolower($month));

        $custom = $this->html(OwnerSummaryExportPdf::TYPE_DETAILS, $this->report(['period' => ['key' => 'custom']]));
        $this->assertStringContainsString('New in the selected range', $custom);
    }

    /** The summary report is one page, even for a busy period. */
    #[Test]
    public function the_summary_report_fits_on_one_page(): void
    {
        $this->assertSame(1, app(OwnerSummaryExportPdf::class)->pageCount(OwnerSummaryExportPdf::TYPE_SUMMARY, $this->report()));
    }

    /** None of the first pass's colours: no navy and gold bands or pastel tiles (the summary's teal comes from the owner's own design). */
    #[Test]
    public function both_reports_keep_to_a_plain_palette(): void
    {
        foreach (OwnerSummaryExportPdf::TYPES as $type) {
            $html = $this->html($type);

            foreach (['#001f3f', '#d4af37', '#e8f5ef', '#fde8e7', '#fef0c7', '#eef2fe', '#f1eefc', '#bcd0e6'] as $colour) {
                $this->assertStringNotContainsStringIgnoringCase($colour, $html, "{$type} uses {$colour}");
            }
        }
    }

    /** The host's document logo heads every page at a size that reads, not as a thumbnail. */
    #[Test]
    public function the_logo_is_printed_large_enough_to_read(): void
    {
        $this->assertGreaterThanOrEqual(36, OwnerSummaryExportPdf::LOGO_HEIGHT);

        foreach (OwnerSummaryExportPdf::TYPES as $type) {
            $data = app(OwnerSummaryExportPdf::class)->data($type, $this->report());
            $this->assertSame(OwnerSummaryExportPdf::LOGO_HEIGHT, $data['logoPrintHeight']);
        }
    }

    /** The details report sets every metric out as a statement - metric, value, change, breakdown. */
    #[Test]
    public function the_details_report_sets_out_every_metric(): void
    {
        $html = $this->html(OwnerSummaryExportPdf::TYPE_DETAILS);

        foreach (['At a Glance', 'Metric', 'Change', 'Breakdown'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringContainsString('vs $11.0K before', $html);
    }

    /** The details report opens with a cover page: title, period and its contents. */
    #[Test]
    public function the_details_report_opens_with_a_cover_page(): void
    {
        $html = $this->html(OwnerSummaryExportPdf::TYPE_DETAILS);

        $cover = strstr($html, 'class="cover"');
        $this->assertNotFalse($cover, 'the details report has a cover');
        $cover = strstr($cover, 'page-break-after', true);
        $this->assertStringContainsString('Contents', $cover);
        $this->assertStringContainsString('Details Report', $cover);
        $this->assertStringContainsString('1 – 15 Nov 2026', $cover);
        $this->assertGreaterThanOrEqual(2, app(OwnerSummaryExportPdf::class)->pageCount(OwnerSummaryExportPdf::TYPE_DETAILS, $this->report()));
    }

    /** Both reports share the agreed branded design: the accent bar, the barred section headings and the cards. */
    #[Test]
    public function both_reports_share_the_branded_design(): void
    {
        foreach (OwnerSummaryExportPdf::TYPES as $type) {
            $html = $this->html($type);

            $this->assertStringContainsString('class="accent"', $html, "{$type} has the accent bar");
            $this->assertStringContainsString('class="sh-bar"', $html, "{$type} has the barred headings");
            $this->assertStringContainsString('class="card hero"', $html, "{$type} has the performance cards");
        }
    }

    /** Small text is printed dark enough to read: no pale grey text. */
    #[Test]
    public function both_reports_keep_their_text_readable(): void
    {
        foreach (OwnerSummaryExportPdf::TYPES as $type) {
            $html = $this->html($type);

            foreach (['#8593a0', '#9ca3af'] as $pale) {
                $this->assertStringNotContainsStringIgnoringCase($pale, $html, "{$type} uses the pale grey {$pale}");
            }
        }
    }

    #[Test]
    public function the_weekly_report_still_says_last_week(): void
    {
        $presenter = new OwnerReportPresenter(require base_path('tests/Fixtures/owner_weekly_report.php'), 'Four Corner');

        $this->assertSame('last week', $presenter->when());
        $this->assertSame('Last week', $presenter->periodColumn());
    }
}
