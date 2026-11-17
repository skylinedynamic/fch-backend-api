<?php

namespace Tests\Unit\OwnerSummary;

use App\Services\FCH\Common\OwnerSummary\OwnerReportPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The report says what the Owner Summary screen says (react-fch owner-summary/components). */
#[CoversClass(OwnerReportPresenter::class)]
class OwnerReportPresenterTest extends TestCase
{
    private function presenter(array $changes = []): OwnerReportPresenter
    {
        $report = array_replace_recursive(require base_path('tests/Fixtures/owner_weekly_report.php'), $changes);

        return new OwnerReportPresenter($report, 'Four Corner', 'https://portal.test');
    }

    public static function amounts(): array
    {
        return [
            [950, '$950', '$950'],
            [14300, '$14,300', '$14.3K'],
            [2310000, '$2,310,000', '$2.31M'],
            [0, '$0', '$0'],
        ];
    }

    #[Test]
    #[DataProvider('amounts')]
    public function money_reads_like_the_screen(float $amount, string $whole, string $compact): void
    {
        $this->assertSame($whole, OwnerReportPresenter::money($amount));
        $this->assertSame($compact, OwnerReportPresenter::compactMoney($amount));
    }

    public static function moves(): array
    {
        return [
            [120, 100, '+20%', 'up'],
            [75, 100, '-25%', 'down'],
            [100, 100, '0%', 'flat'],
            [5, 0, 'new', 'up'],
            [0, 0, 'no change', 'flat'],
        ];
    }

    #[Test]
    #[DataProvider('moves')]
    public function a_move_reads_like_the_screen(float $current, float $previous, string $text, string $tone): void
    {
        $this->assertSame(['text' => $text, 'tone' => $tone], OwnerReportPresenter::delta($current, $previous));
    }

    /** Partners and clients on the platform, each as total, active and new this period. */
    #[Test]
    public function it_counts_the_partners_and_clients_on_the_platform(): void
    {
        $this->assertSame([
            'partners' => ['total' => '26', 'active' => '22', 'new' => 3],
            'clients' => ['total' => '410', 'active' => '380', 'new' => 21],
        ], $this->presenter(['kpis' => ['partners' => ['total' => 26], 'clients' => ['total' => 410]]])->community());
    }

    #[Test]
    public function it_gives_the_seven_headline_cards(): void
    {
        $kpis = $this->presenter()->kpis();

        $this->assertSame(
            ['Net Earnings', 'Funded Volume', 'Funded Deals', 'Partners', 'Clients', 'Leads', 'Lead-To-Funded'],
            array_column($kpis, 'label')
        );
        $this->assertSame(
            ['label' => 'Net Earnings', 'value' => '$14.3K', 'tag' => '+30%', 'tone' => 'up', 'note' => 'vs $11.0K before', 'rows' => []],
            $kpis[0]
        );

        $this->assertSame('$2.31M', $kpis[1]['value']);
        $this->assertSame('-22%', $kpis[1]['tag']);
        $this->assertSame([['label' => 'FCH', 'value' => '$1.31M'], ['label' => 'Partners', 'value' => '$1.00M']], $kpis[1]['rows']);

        $this->assertSame('6', $kpis[2]['value']);
        $this->assertSame('-25%', $kpis[2]['tag']);
        $this->assertSame([['label' => 'FCH', 'value' => '4'], ['label' => 'Partners', 'value' => '2']], $kpis[2]['rows']);

        $this->assertSame('26', $kpis[3]['value']);
        $this->assertSame([['label' => 'Active', 'value' => '22'], ['label' => 'New last week', 'value' => '3']], $kpis[3]['rows']);

        $this->assertSame('410', $kpis[4]['value']);
        $this->assertSame('380 active · 21 new last week', $kpis[4]['note']);
        $this->assertSame([['label' => 'FCH', 'value' => '150'], ['label' => 'Partners', 'value' => '260']], $kpis[4]['rows']);

        $this->assertSame('41', $kpis[5]['value']);
        $this->assertSame('new', $kpis[5]['tag']);
        $this->assertSame([['label' => 'FCH', 'value' => '15'], ['label' => 'Partners', 'value' => '26']], $kpis[5]['rows']);

        $this->assertSame('12%', $kpis[6]['value']);
        $this->assertSame([['label' => 'FCH', 'value' => '20% (3 of 15)'], ['label' => 'Partners', 'value' => '8% (2 of 26)']], $kpis[6]['rows']);
    }

    #[Test]
    public function a_conversion_with_no_leads_shows_a_dash_not_a_zero(): void
    {
        $kpis = $this->presenter(['kpis' => ['lead_conversion' => [
            'host' => ['leads' => 0, 'funded' => 0],
            'partners' => ['leads' => 0, 'funded' => 0],
        ]]])->kpis();

        $this->assertSame('—', $kpis[6]['value']);
        $this->assertSame('—', $kpis[6]['rows'][0]['value']);
    }

    #[Test]
    public function it_lists_every_attention_item_and_can_keep_only_what_waits(): void
    {
        $presenter = $this->presenter();
        $items = collect($presenter->attention())->keyBy('label');

        $this->assertCount(11, $items);
        $this->assertSame('$1,000 where payment failed', $items['Failed invoices']['sub']);
        $this->assertSame(3, $items['Failed or disputed payments']['count']);
        $this->assertSame('2 failed · 1 disputed last week', $items['Failed or disputed payments']['sub']);
        $this->assertSame('$31,000 owed to partners and staff', $items['Commission payouts due']['sub']);
        $this->assertSame('1 partner · 2 clients within 14 days', $items['Free trials ending']['sub']);
        $this->assertSame('https://portal.test/commissions', $items['Commission payouts due']['url']);
        $this->assertSame('zero', $items['Suspended partners']['tone']);
        $this->assertSame('red', $items['Failed invoices']['tone']);

        $this->assertNotContains('Suspended partners', array_column($presenter->attentionWaiting(), 'label'));
        $this->assertCount(10, $presenter->attentionWaiting());
    }

    #[Test]
    public function it_totals_cash_by_stream_with_the_screens_labels(): void
    {
        $streams = $this->presenter()->streams();

        $this->assertSame('Partner enrollment', $streams['rows'][0]['label']);
        $this->assertSame('$6,000', $streams['rows'][0]['amount']);
        $this->assertSame('Four Corner client enrollment', $streams['rows'][4]['label']);
        $this->assertSame('Clients enrolled directly with Four Corner', $streams['rows'][4]['note']);
        $this->assertSame('59 payments', $streams['payments']);
        $this->assertSame('$14,300', $streams['amount']);
    }

    #[Test]
    public function it_splits_what_is_owed_by_how_late_it_is(): void
    {
        $owed = $this->presenter()->owed();

        $this->assertSame('$9.4K', $owed['amount']);
        $this->assertSame('12 invoices', $owed['invoices']);
        $this->assertSame(['Unpaid over 30 days', 'Unpaid under 30 days', 'Payment failed'], array_column($owed['parts'], 'label'));
        $this->assertSame(34, $owed['parts'][0]['percent']);
        $this->assertSame('4 · $3,200', $owed['parts'][0]['detail']);
    }

    #[Test]
    public function it_draws_stages_against_the_largest_and_names_their_span(): void
    {
        $pipeline = $this->presenter()->pipeline();

        $this->assertSame('40 open · $5.20M requested', $pipeline['summary']);
        $this->assertSame('Intake', $pipeline['stages'][0]['label']);
        $this->assertSame('Submitted to Documents Pending', $pipeline['stages'][0]['statuses']);
        $this->assertSame(100, $pipeline['stages'][0]['percent']);
        $this->assertSame(25, $pipeline['stages'][2]['percent']);
        $this->assertSame('$2,000,000', $pipeline['stages'][0]['requested']);
        $this->assertSame(['label' => 'Reached funded', 'count' => 6], $pipeline['activity'][4]);
        $this->assertStringContainsString('20 days median to fund', $pipeline['note']);
    }

    #[Test]
    public function a_week_with_nothing_funded_says_so(): void
    {
        $this->assertStringContainsString('No deal was funded last week.', $this->presenter(['pipeline' => ['median_days_to_fund' => null]])->pipeline()['note']);
    }

    #[Test]
    public function lead_sources_run_largest_first_and_leave_out_empty_ones(): void
    {
        $sources = $this->presenter()->leadSources();

        $this->assertSame('41 leads, by lead source', $sources['summary']);
        $this->assertSame(['Referral Partner', 'Website', 'Source not set'], array_column($sources['rows'], 'name'));
        $this->assertSame(73, $sources['rows'][0]['percent']);
    }

    #[Test]
    public function charts_scale_to_the_largest_month_and_mark_the_latest(): void
    {
        [$funded, $cash] = $this->presenter()->charts();

        $this->assertSame('Funded Volume By Month', $funded['title']);
        $this->assertSame(['text' => '2.1× since Jun', 'tone' => 'up'], $funded['trend']);
        $this->assertSame('$2.31M', $funded['bars'][5]['value']);
        $this->assertSame(OwnerReportPresenter::CHART_BAR_AREA, $funded['bars'][5]['height']);
        $this->assertTrue($funded['bars'][5]['current']);
        $this->assertSame('—', $funded['bars'][3]['value']);
        $this->assertSame(0, $funded['bars'][3]['height']);
        $this->assertSame('Sep', $funded['bars'][3]['month']);
        $this->assertSame(['text' => '-14% since Jun', 'tone' => 'down'], $cash['trend']);
    }

    #[Test]
    public function top_partners_and_stalled_deals_read_like_their_tables(): void
    {
        $presenter = $this->presenter();

        $partners = $presenter->topPartners();
        $this->assertSame('White-label', $partners[0]['type']);
        $this->assertSame('wl', $partners[0]['type_tone']);
        $this->assertSame('Certified', $partners[1]['type']);
        $this->assertSame('$1,200,000', $partners[0]['volume']);

        $stalled = $presenter->stalled();
        $this->assertSame('12 deals · $840.0K requested', $stalled['summary']);
        $this->assertSame('Showing 2 of 12, longest waiting first', $stalled['showing']);
        $this->assertSame('FUND-000044', $stalled['rows'][0]['application']);
        $this->assertSame('45 days', $stalled['rows'][0]['idle']);
        $this->assertTrue($stalled['rows'][0]['long']);
        $this->assertSame('#5', $stalled['rows'][1]['application']);
        $this->assertSame('—', $stalled['rows'][1]['business']);
        $this->assertFalse($stalled['rows'][1]['long']);
    }

    #[Test]
    public function the_network_and_the_period_lines_are_ready_to_print(): void
    {
        $presenter = $this->presenter();

        $network = $presenter->network();
        $this->assertSame('18 of 26', $network['active']);
        $this->assertSame(69, $network['active_percent']);
        $this->assertSame('4 · 1 end within 14 days', $network['on_trial']);

        $this->assertSame('1 – 7 Nov 2026', $presenter->periodLabel());
        $this->assertSame('25 – 31 Oct 2026', $presenter->comparedWithLabel());
        $this->assertSame('https://portal.test/business-insights', $presenter->dashboardUrl());
    }
}
