<?php

namespace Tests\Integration\OwnerSummary;

use App\Console\Commands\SendOwnerWeeklyReport;
use App\Mail\OwnerSummary\OwnerWeeklyReportMail;
use App\Models\Invoice\Invoice;
use App\Models\OwnerSummary\OwnerReportSetting;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Services\FCH\Common\OwnerSummary\OwnerWeeklyReportPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The Sunday report. "Now" is Sunday 2026-11-08 10:00 Eastern, so last week is
 * Sunday 1 to Saturday 7 November.
 */
#[CoversClass(SendOwnerWeeklyReport::class)]
#[CoversClass(OwnerWeeklyReportMail::class)]
#[CoversClass(OwnerWeeklyReportPdf::class)]
class WeeklyReportCommandTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const COMMAND = 'owner-summary:send-weekly-report';

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00', 'America/New_York'));

        $this->host = $this->makeHostPartner();
        $this->makePartnerAdmin($this->host);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(bool $enabled = true, array $recipients = ['owner@example.test', 'finance@example.test']): OwnerReportSetting
    {
        return OwnerReportSetting::create(['partner_id' => $this->host->id, 'is_enabled' => $enabled, 'recipients' => $recipients]);
    }

    private function cash(float $amount, string $moment, int $environment = PaymentProvider::ENVIRONMENT_PRODUCTION): void
    {
        $invoice = Invoice::forceCreate([
            'invoice_id' => 'INV-' . Str::random(8),
            'partner_id' => $this->host->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
            'type' => Invoice::INVOICE_TYPE_PLATFORM_FEE,
            'payment_amount' => $amount,
            'status' => Invoice::STATUS_PAID,
            'currency' => 'USD',
            'currency_symbol' => '$',
        ]);

        Payment::forceCreate([
            'partner_id' => $this->host->id,
            'invoice_id' => $invoice->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
            'transaction_id' => 'TX_' . Str::random(8),
            'amount' => $amount,
            'status' => Payment::STATUS_SUCCEEDED,
            'is_environment' => $environment,
            'transaction_date' => Carbon::parse($moment, 'America/New_York'),
        ]);
    }

    private function sentMail(): OwnerWeeklyReportMail
    {
        $sent = Mail::queued(OwnerWeeklyReportMail::class);
        $this->assertCount(1, $sent);

        return $sent->first();
    }

    #[Test]
    public function it_sends_last_weeks_live_numbers_to_everyone_on_the_list(): void
    {
        $this->settings();
        $this->cash(500, '2026-11-03 12:00:00');   // last week
        $this->cash(70, '2026-10-28 12:00:00');    // the week before
        $this->cash(900, '2026-11-08 09:00:00');   // this week, not reported yet
        $this->cash(40, '2026-11-03 12:00:00', PaymentProvider::ENVIRONMENT_SANDBOX); // not real money

        $this->assertSame(0, Artisan::call(self::COMMAND));

        $mail = $this->sentMail();
        $this->assertTrue($mail->hasTo('owner@example.test'));
        $this->assertTrue($mail->hasTo('finance@example.test'));
        $this->assertSame('2026-11-01', $mail->report['period']['from']);
        $this->assertSame('2026-11-07', $mail->report['period']['to']);
        $this->assertSame('production', $mail->report['environment']);
        $this->assertEquals(['current' => 500, 'previous' => 70], $mail->report['kpis']['cash_collected']);
        $this->assertSame($this->host->id, $mail->sendingPartnerId());
    }

    #[Test]
    public function the_message_names_the_week_shows_the_numbers_and_attaches_a_pdf(): void
    {
        $this->settings();
        $this->cash(500, '2026-11-03 12:00:00');

        Artisan::call(self::COMMAND);

        $mail = $this->sentMail();
        $mail->assertHasSubject('Weekly owner report: 1 – 7 Nov 2026');
        $mail->assertSeeInHtml('1 – 7 Nov 2026');
        $mail->assertSeeInHtml('Net Earnings');
        $mail->assertSeeInHtml('$500');
        $mail->assertHasAttachedData(
            $mail->pdf(),
            'Owner-report-2026-11-01-to-2026-11-07.pdf',
            ['mime' => 'application/pdf']
        );
        $this->assertStringStartsWith('%PDF', $mail->pdf());
    }

    #[Test]
    public function the_pdf_page_shows_the_period_and_the_numbers(): void
    {
        $this->settings();
        $this->cash(500, '2026-11-03 12:00:00');

        Artisan::call(self::COMMAND);

        $pdf = app(OwnerWeeklyReportPdf::class);
        $html = view(OwnerWeeklyReportPdf::VIEW, $pdf->data($this->sentMail()->report))->render();

        $this->assertStringContainsString('1 – 7 Nov 2026', $html);
        $this->assertStringContainsString('Net Earnings', $html);
        $this->assertStringContainsString('Platform fees', $html);
        $this->assertStringContainsString('$500', $html);
    }

    private function busyReport(): array
    {
        return require base_path('tests/Fixtures/owner_weekly_report.php');
    }

    #[Test]
    public function the_pdf_has_every_section_of_the_screen(): void
    {
        $pdf = app(OwnerWeeklyReportPdf::class);
        $html = view(OwnerWeeklyReportPdf::VIEW, $pdf->data($this->busyReport()))->render();

        foreach ([
            'Owner Summary', 'Net Earnings', 'Funded Volume', 'Funded Deals', 'Partners', 'Clients', 'Leads', 'Lead-To-Funded', 'Active partners',
            'Needs Attention', 'Funding Commissions', 'Owed To ', 'Open Applications', 'Where Leads Come From',
            'Funded Volume By Month', 'Net Earnings By Month', 'Partner Network', 'Top Partners', 'Stalled Deals',
            'How These Numbers Are Counted',
            '$14.3K', '+30%', 'Total collected', '$14,300', 'FUND-000044', 'Partner One', '2.1× since Jun', 'Reached funded',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringContainsString('Acme &amp; Sons LLC', $html);
        // dompdf has no 600 weight: it falls back to a serif face.
        $this->assertStringNotContainsString('font-weight: 600', $html);
        $this->assertStringStartsWith('%PDF', $pdf->render($this->busyReport()));
    }

    #[Test]
    public function the_email_gives_the_week_at_a_glance_and_links_to_the_dashboard(): void
    {
        $html = (new OwnerWeeklyReportMail($this->busyReport(), '%PDF', $this->host->id))->render();

        foreach ([
            '1 – 7 Nov 2026', '25 – 31 Oct 2026',
            'Net Earnings', '$14.3K', '+30%', 'Funded Volume', '$1.31M', 'Funded Deals', 'Lead-To-Funded', '20% (3 of 15)',
            'Needs Attention', 'Commission payouts due', '$31,000 owed to partners and staff',
            'Partner enrollment', 'Total collected', '$14,300',
            'Funding Commissions', 'Commission on deals closed', 'Payouts still due', '$28,000',
            'Unpaid over 30 days', 'Open Applications', '40 open · $5.20M requested',
            'Where Leads Come From', 'Referral Partner', 'Top Partners', 'Partner One', 'Stalled Deals', 'FUND-000044', 'Partner Network',
            'Open Owner Summary', '/business-insights', '/commissions', 'attached',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('not cash until the lender pays', $html);
    }

    private function assertAppearsInOrder(array $texts, string $html): void
    {
        $positions = array_map(fn (string $text) => strpos($html, $text), $texts);

        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }

    #[Test]
    public function net_earnings_commissions_and_what_is_owed_follow_the_top_cards_as_on_the_screen(): void
    {
        $order = ['Lead-To-Funded', 'Total collected', 'Funding Commissions', 'Owed To ', 'Needs Attention'];
        $pdf = app(OwnerWeeklyReportPdf::class);

        $this->assertAppearsInOrder($order, view(OwnerWeeklyReportPdf::VIEW, $pdf->data($this->busyReport()))->render());
        $this->assertAppearsInOrder($order, (new OwnerWeeklyReportMail($this->busyReport(), '%PDF', $this->host->id))->render());
    }

    #[Test]
    public function the_email_body_has_every_section_the_pdf_has(): void
    {
        $html = (new OwnerWeeklyReportMail($this->busyReport(), '%PDF', $this->host->id))->render();

        foreach ([
            'Owner Summary', 'Suspended partners', 'Pre-qualifications pending', 'Funded Volume By Month', 'Net Earnings By Month',
            '2.1× since Jun', 'Reached funded', 'Paid out to staff', 'White-label', 'Commission paid',
            'Acme &amp; Sons LLC', 'Days idle', 'How These Numbers Are Counted',
        ] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        // Card titles read as written, not forced into capitals.
        $this->assertDoesNotMatchRegularExpression('/text-transform: uppercase[^>]*>Net Earnings/', $html);
        $this->assertDoesNotMatchRegularExpression('/text-transform: uppercase[^>]*>Other Ways In/', $html);
        $this->assertStringContainsString('FCH share booked', $html);
        $this->assertStringNotContainsString('>Host<', $html);
        $this->assertStringNotContainsString('Host share', $html);
        // The two wide tables scroll on a phone instead of losing their last columns.
        $this->assertSame(2, substr_count($html, 'overflow-x: auto'));
    }

    #[Test]
    public function a_quiet_week_is_said_plainly_in_the_email(): void
    {
        $this->settings();
        Artisan::call(self::COMMAND);

        $html = $this->sentMail()->render();

        $this->assertStringContainsString('No stalled deals.', $html);
        $this->assertStringContainsString('No partner activity last week.', $html);
        $this->assertStringContainsString('No new leads last week.', $html);
    }

    #[Test]
    public function a_quiet_week_still_makes_a_pdf(): void
    {
        $this->settings();
        Artisan::call(self::COMMAND);

        $this->assertStringStartsWith('%PDF', $this->sentMail()->pdf());
    }

    #[Test]
    public function nothing_goes_out_when_it_is_off_never_set_up_or_has_nobody_on_it(): void
    {
        Artisan::call(self::COMMAND);
        Mail::assertNothingOutgoing();

        $setting = $this->settings(false);
        Artisan::call(self::COMMAND);
        Mail::assertNothingOutgoing();

        $setting->update(['is_enabled' => true, 'recipients' => []]);
        Artisan::call(self::COMMAND);
        Mail::assertNothingOutgoing();
    }

    #[Test]
    public function every_run_sends_the_report_even_for_a_week_already_sent(): void
    {
        $this->settings();

        Artisan::call(self::COMMAND);
        Artisan::call(self::COMMAND);

        Mail::assertQueuedCount(2);
    }

    #[Test]
    public function the_last_week_sent_is_kept_as_a_record(): void
    {
        $setting = $this->settings();
        $this->assertNull($setting->last_sent_period_from);

        Artisan::call(self::COMMAND);
        $this->assertSame('2026-11-01', $setting->fresh()->last_sent_period_from->toDateString());

        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00', 'America/New_York'));
        Artisan::call(self::COMMAND);
        $this->assertSame('2026-11-08', $setting->fresh()->last_sent_period_from->toDateString());
    }

    #[Test]
    public function a_run_that_sends_nothing_says_why(): void
    {
        $this->settings(false);

        $this->artisan(self::COMMAND)
            ->expectsOutput('The weekly owner report is off or has no recipients, so nothing was sent.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_fails_loudly_when_the_host_has_no_owner_login(): void
    {
        $this->settings();
        $this->host->partnerUser()->delete();

        $this->assertSame(1, Artisan::call(self::COMMAND));
        Mail::assertNothingOutgoing();
    }
}
