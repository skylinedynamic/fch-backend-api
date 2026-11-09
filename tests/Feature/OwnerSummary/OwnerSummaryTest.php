<?php

namespace Tests\Feature\OwnerSummary;

use App\Http\Controllers\Api\V1\FCH\Common\OwnerSummary\OwnerSummaryController;
use App\Models\Audit\FundingApplicationAuditEvent;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Commission\Commission;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Invoice\Invoice;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Services\FCH\Common\OwnerSummary\OwnerSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The host owner's business summary (GET /api/v1/common/owner-summary), built only
 * from what the database already records - no churn, no cohort conversion.
 *
 * "Today" is 2026-11-15 (UTC); the default period is this month, compared with
 * last month. The book:
 *
 *   cash (production, succeeded, paid to the host)
 *     11-05  partner enrollment   1,000   P1
 *     11-06  platform fee           200   P1
 *     11-07  borrower fee            50   P1 pays the host for its client
 *     11-08  host client installment 300
 *     10-20  platform fee           400   (last month)
 *   not the host's cash
 *     P1's own client paying P1 999 · a commission payout 500 · a sandbox user seat 75
 *   owed to the host
 *     unpaid user seat 120 (raised 11-10) · unpaid host client 80 (raised 09-01)
 *     failed platform fee 200 · a manual payment of 60 awaiting approval
 *
 *   deals
 *     A1 P1 client, opened 10-11, funded 100k on 11-10 (host 6,000 / P1 4,000)
 *     A2 host client, opened 11-02, funded 50k on 11-12 (host 5,000)
 *     A3 host client, funded 20k on 10-15 (last month)
 *     A4 Underwriting Review 30k, untouched since 10-01 (stalled)
 *     A5 opened 11-01, Lender Reviewing 70k since 11-14
 *     a Draft and a Denied application (not open)
 */
#[CoversClass(OwnerSummaryController::class)]
#[CoversClass(OwnerSummaryService::class)]
class OwnerSummaryTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const TODAY = '2026-11-15 12:00:00';

    private Partner $host;
    private Partner $p1;
    private Partner $p2;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->at(self::TODAY);

        $this->host = $this->makeHostPartner();
        $this->p1 = $this->makeWhiteLabelPartner(['company_name' => 'Partner One']);
        $this->p2 = $this->makeCertifiedPartner([
            'company_name' => 'Partner Two',
            'status' => Partner::STATUS_SUSPENDED,
            'is_free_trail' => true,
            'free_trail_end_date' => '2026-11-20',
        ]);
        $this->owner = $this->makePartnerAdmin($this->host);

        $this->seedMoney();
        $this->seedDeals();
        $this->seedGrowth();

        $this->at(self::TODAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $moment): void
    {
        Carbon::setTestNow(Carbon::parse($moment, 'UTC'));
    }

    // ------------------------------------------------------------------ book

    private function invoice(array $attributes): Invoice
    {
        return Invoice::forceCreate(array_merge([
            'invoice_id' => 'INV-' . Str::random(8),
            'currency' => 'USD',
            'currency_symbol' => '$',
        ], $attributes));
    }

    private function partnerInvoice(Partner $partner, string $type, float $amount, string $status): Invoice
    {
        return $this->invoice([
            'partner_id' => $partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'type' => $type,
            'payment_amount' => $amount,
            'status' => $status,
        ]);
    }

    private function payment(Invoice $invoice, float $amount, string $status, string $on, int $environment = PaymentProvider::ENVIRONMENT_PRODUCTION, bool $manual = false): Payment
    {
        return Payment::forceCreate([
            'partner_id' => $invoice->partner_id,
            'borrower_id' => $invoice->borrower_id,
            'invoice_id' => $invoice->id,
            'entity_type' => $invoice->entity_type,
            'entity_id' => $invoice->entity_id,
            'transaction_id' => 'TX_' . Str::random(8),
            'amount' => $amount,
            'status' => $status,
            'is_environment' => $environment,
            'is_manual' => $manual,
            'transaction_date' => Carbon::parse($on . ' 10:00:00', 'UTC'),
        ]);
    }

    private function seedMoney(): void
    {
        $hostClient = $this->makeBorrower($this->host, ['first_name' => 'Hosted']);
        $p1Client = $this->makeBorrower($this->p1, ['first_name' => 'Partnered']);

        $paid = Invoice::STATUS_PAID;
        $this->payment($this->partnerInvoice($this->p1, Invoice::INVOICE_TYPE_ENROLLMENT, 1000, $paid), 1000, Payment::STATUS_SUCCEEDED, '2026-11-05');
        $this->payment($this->partnerInvoice($this->p1, Invoice::INVOICE_TYPE_PLATFORM_FEE, 200, $paid), 200, Payment::STATUS_SUCCEEDED, '2026-11-06');
        $this->payment($this->partnerInvoice($this->p1, Invoice::INVOICE_TYPE_BORROWER_FEE, 50, $paid), 50, Payment::STATUS_SUCCEEDED, '2026-11-07');
        $this->payment($this->invoice([
            'partner_id' => $this->host->id,
            'borrower_id' => $hostClient->id,
            'entity_type' => Borrower::class,
            'entity_id' => $hostClient->id,
            'type' => Invoice::INVOICE_TYPE_INSTALLMENT,
            'payment_amount' => 300,
            'status' => $paid,
        ]), 300, Payment::STATUS_SUCCEEDED, '2026-11-08');
        $this->payment($this->partnerInvoice($this->p1, Invoice::INVOICE_TYPE_PLATFORM_FEE, 400, $paid), 400, Payment::STATUS_SUCCEEDED, '2026-10-20');

        // Not the host's cash.
        $this->payment($this->invoice([
            'partner_id' => $this->p1->id,
            'borrower_id' => $p1Client->id,
            'entity_type' => Borrower::class,
            'entity_id' => $p1Client->id,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
            'payment_amount' => 999,
            'status' => $paid,
        ]), 999, Payment::STATUS_SUCCEEDED, '2026-11-08');
        $this->payment($this->partnerInvoice($this->p1, Invoice::INVOICE_TYPE_COMMISSION_PAYOUT, 500, $paid), 500, Payment::STATUS_SUCCEEDED, '2026-11-09');
        $this->payment($this->partnerInvoice($this->p1, Invoice::INVOICE_TYPE_USER_SEAT, 75, $paid), 75, Payment::STATUS_SUCCEEDED, '2026-11-09', PaymentProvider::ENVIRONMENT_SANDBOX);

        // Owed to the host.
        $this->at('2026-11-10 09:00:00');
        $this->partnerInvoice($this->p1, Invoice::INVOICE_TYPE_USER_SEAT, 120, Invoice::STATUS_UNPAID);
        $this->at('2026-09-01 09:00:00');
        $this->invoice([
            'partner_id' => $this->host->id,
            'borrower_id' => $hostClient->id,
            'entity_type' => Borrower::class,
            'entity_id' => $hostClient->id,
            'type' => Invoice::INVOICE_TYPE_INSTALLMENT,
            'payment_amount' => 80,
            'status' => Invoice::STATUS_UNPAID,
        ]);
        $this->at(self::TODAY);
        $failed = $this->partnerInvoice($this->p1, Invoice::INVOICE_TYPE_PLATFORM_FEE, 200, Invoice::STATUS_FAILED);
        $this->payment($failed, 200, Payment::STATUS_FAILED, '2026-11-10');
        $manual = $this->partnerInvoice($this->p1, Invoice::INVOICE_TYPE_PLATFORM_FEE, 60, Invoice::STATUS_PROCESSING);
        $this->payment($manual, 60, Payment::STATUS_PROCESSING, '2026-11-11', manual: true);

        // P1's client owing P1 is not owed to the host.
        $this->invoice([
            'partner_id' => $this->p1->id,
            'borrower_id' => $p1Client->id,
            'entity_type' => Borrower::class,
            'entity_id' => $p1Client->id,
            'type' => Invoice::INVOICE_TYPE_INSTALLMENT,
            'payment_amount' => 500,
            'status' => Invoice::STATUS_UNPAID,
        ]);
    }

    // ----------------------------------------------------------------- deals

    private function application(Partner $partner, string $status, float $requested, string $openedOn): FundingApplication
    {
        $this->at($openedOn . ' 09:00:00');
        $borrower = $this->makeBorrower($partner, ['business_name' => 'Biz ' . Str::random(4)]);

        return FundingApplication::forceCreate([
            'borrower_id' => $borrower->id,
            'partner_id' => $partner->id,
            'full_name' => 'Client ' . $borrower->id,
            'status' => $status,
            'requested_amount' => $requested,
        ]);
    }

    private function moveTo(FundingApplication $application, string $status, string $on, ?float $funded = null): void
    {
        $this->at($on . ' 15:00:00');
        $application->status = $status;
        if ($funded !== null) {
            $application->total_funded_amount = $funded;
            $application->funded_date = $on;
        }
        $application->save();
    }

    private function commission(FundingApplication $application, float $hostShare, float $partnerShare): Commission
    {
        $total = $hostShare + $partnerShare;

        return Commission::create([
            'funding_application_id' => $application->id,
            'borrower_id' => $application->borrower_id,
            'partner_id' => $application->partner_id,
            'total_commission' => 10,
            'admin_commission' => $total > 0 ? $hostShare / $total * 100 : 100,
            'partner_commission' => $total > 0 ? $partnerShare / $total * 100 : 0,
            'total_commission_amount' => $total,
            'admin_commission_amount' => $hostShare,
            'partner_commission_amount' => $partnerShare,
            'commission_status' => Commission::STATUS_PROCESSING,
            'payment_status' => Commission::PAYMENT_STATUS_UNPAID,
        ]);
    }

    private function seedDeals(): void
    {
        $a1 = $this->application($this->p1, FundingApplication::SUBMITTED, 100000, '2026-10-11');
        $this->moveTo($a1, FundingApplication::CLOSED_COMPLETED, '2026-11-10', 100000);
        $this->commission($a1, 6000, 4000);

        $a2 = $this->application($this->host, FundingApplication::SUBMITTED, 50000, '2026-11-02');
        $this->moveTo($a2, FundingApplication::CLOSED_COMPLETED, '2026-11-12', 50000);
        $this->commission($a2, 5000, 0);

        $a3 = $this->application($this->host, FundingApplication::SUBMITTED, 20000, '2026-10-01');
        $this->moveTo($a3, FundingApplication::CLOSED_COMPLETED, '2026-10-15', 20000);

        $this->application($this->host, FundingApplication::UNDERWRITING_REVIEW, 30000, '2026-10-01');

        $a5 = $this->application($this->p1, FundingApplication::SUBMITTED, 70000, '2026-11-01');
        $this->moveTo($a5, FundingApplication::LENDER_REVIEWING, '2026-11-14');

        $this->application($this->host, FundingApplication::DRAFT, 90000, '2026-11-03');
        $this->application($this->host, FundingApplication::DENIED, 15000, '2026-11-04');
    }

    // ---------------------------------------------------------------- growth

    private function lead(?Partner $partner, ?string $source, string $on): Lead
    {
        $this->at($on . ' 09:00:00');

        return Lead::forceCreate([
            'partner_id' => $partner?->id,
            'lead_source' => $source,
            'contact_person' => 'Lead ' . Str::random(4),
            'email' => 'lead-' . uniqid() . '@example.test',
            'status' => Lead::STATUS_NEW_LEAD,
        ]);
    }

    private function submission(Partner $partner, string $status): void
    {
        EmbedFormSubmission::forceCreate([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $partner->id,
            'first_name' => 'Embed',
            'last_name' => 'Applicant',
            'email' => 'embed-' . uniqid() . '@example.test',
            'status' => $status,
        ]);
    }

    private function seedGrowth(): void
    {
        $this->lead($this->p1, Lead::LEAD_SOURCE_WEBSITE, '2026-11-03');
        $this->lead($this->p1, Lead::LEAD_SOURCE_REFERRAL_PARTNER, '2026-11-04');
        $this->lead($this->host, null, '2026-11-05');
        $this->lead($this->host, Lead::LEAD_SOURCE_WEBSITE, '2026-10-05');
        $this->lead($this->p2, Lead::LEAD_SOURCE_COLD_OUTREACH, '2026-10-01');

        $this->at('2026-11-06 09:00:00');
        PreQualificationRequest::forceCreate([
            'partner_id' => $this->host->id,
            'first_name' => 'Pre',
            'last_name' => 'Qual',
            'email' => 'pq-' . uniqid() . '@example.test',
            'status' => PreQualificationRequest::PENDING,
        ]);

        $this->submission($this->host, EmbedFormSubmission::STATUS_NEW);
        $this->submission($this->host, EmbedFormSubmission::STATUS_CONVERTED);
        $this->submission($this->p1, EmbedFormSubmission::STATUS_NEW);

        // A client enrolled last month.
        $this->at('2026-10-05 09:00:00');
        $this->makeBorrower($this->host, ['first_name' => 'Older']);
    }

    // ----------------------------------------------------------------- calls

    private function summary(User $user, array $query = []): TestResponse
    {
        $this->actingAsUser($user);

        return $this->withHeader('X-Timezone', 'UTC')
            ->getJson('/api/v1/common/owner-summary' . ($query ? '?' . http_build_query($query) : ''));
    }

    private function data(array $query = []): array
    {
        return $this->summary($this->owner, $query)->assertOk()->json('data');
    }

    // ----------------------------------------------------------------- tests

    #[Test]
    public function only_the_host_owner_may_see_it(): void
    {
        $this->getJson('/api/v1/common/owner-summary')->assertUnauthorized();

        $this->summary($this->makeStaffUser($this->host))->assertForbidden();
        $this->summary($this->makePartnerAdmin($this->p1))->assertForbidden();
        $this->summary($this->makeBorrowerUser($this->host))->assertForbidden();

        $this->summary($this->owner)->assertOk();
    }

    #[Test]
    public function an_unknown_period_or_environment_is_refused(): void
    {
        $this->summary($this->owner, ['period' => 'forever'])->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->summary($this->owner, ['environment' => 'staging'])->assertUnprocessable()->assertJsonValidationErrors('environment');
    }

    #[Test]
    public function the_period_is_this_month_against_last_month_by_default(): void
    {
        $period = $this->data()['period'];

        $this->assertSame('this_month', $period['key']);
        $this->assertSame('2026-11-01', $period['from']);
        $this->assertSame('2026-11-30', $period['to']);
        $this->assertSame('2026-10-01', $period['previous_from']);
        $this->assertSame('2026-10-31', $period['previous_to']);
        $this->assertSame('production', $this->data()['environment']);
    }

    #[Test]
    public function cash_collected_is_only_money_paid_to_the_host_by_stream(): void
    {
        $data = $this->data();

        $this->assertEquals(['current' => 1550, 'previous' => 400], $data['kpis']['cash_collected']);
        $this->assertEquals([
            ['stream' => 'partner_enrollment', 'amount' => 1000, 'payments' => 1],
            ['stream' => 'platform_fee', 'amount' => 200, 'payments' => 1],
            ['stream' => 'user_seat', 'amount' => 0, 'payments' => 0],
            ['stream' => 'borrower_fee', 'amount' => 50, 'payments' => 1],
            ['stream' => 'client_enrollment', 'amount' => 300, 'payments' => 1],
        ], $data['cash_by_stream']);
    }

    #[Test]
    public function sandbox_payments_are_counted_only_when_asked_for(): void
    {
        $data = $this->data(['environment' => 'sandbox']);

        $this->assertSame('sandbox', $data['environment']);
        $this->assertEquals(['current' => 75, 'previous' => 0], $data['kpis']['cash_collected']);
    }

    #[Test]
    public function what_is_owed_to_the_host_is_split_by_age_and_failure(): void
    {
        $data = $this->data();

        $this->assertEquals(['amount' => 400, 'invoices' => 3], $data['kpis']['outstanding']);
        $this->assertEquals([
            'unpaid_recent' => ['amount' => 120, 'invoices' => 1],
            'unpaid_over_30_days' => ['amount' => 80, 'invoices' => 1],
            'failed' => ['amount' => 200, 'invoices' => 1],
        ], $data['receivables']);
    }

    #[Test]
    public function funded_volume_and_commissions_follow_deals_closed_completed_in_the_period(): void
    {
        $data = $this->data();

        $this->assertEquals(['amount' => 150000, 'deals' => 2, 'previous_amount' => 20000], $data['kpis']['funded']);
        $this->assertEquals(20, $data['pipeline']['median_days_to_fund']);

        $commissions = $data['commissions'];
        $this->assertEquals(11000, $commissions['host_share_booked']);
        $this->assertEquals(4000, $commissions['partner_share']);
        $this->assertEquals(0, $commissions['paid_to_partners']);
        $this->assertEquals(0, $commissions['paid_to_staff']);
        $this->assertEquals(4000, $commissions['payouts_due']);
    }

    #[Test]
    public function open_applications_are_grouped_by_stage_with_their_requested_amount(): void
    {
        $pipeline = $this->data()['pipeline'];

        $this->assertEquals(['count' => 2, 'requested' => 100000], $pipeline['open']);

        $groups = collect($pipeline['groups'])->keyBy('key');
        $this->assertSame(['intake', 'underwriting', 'approved', 'with_lender', 'closing', 'on_hold'], $groups->keys()->all());
        $this->assertEquals(1, $groups['underwriting']['count']);
        $this->assertEquals(30000, $groups['underwriting']['requested']);
        $this->assertEquals(1, $groups['with_lender']['count']);
        $this->assertEquals(70000, $groups['with_lender']['requested']);
        $this->assertEquals(0, $groups['intake']['count']);
        $this->assertContains(FundingApplication::LENDER_REVIEWING, $groups['with_lender']['statuses']);
    }

    #[Test]
    public function stage_activity_counts_applications_that_reached_each_stage_in_the_period(): void
    {
        $activity = $this->data()['pipeline']['activity'];

        // A5 and A2 opened in November (the Draft and the Denied are not stages).
        $this->assertEquals(2, $activity['intake']);
        $this->assertEquals(1, $activity['with_lender']);
        $this->assertEquals(2, $activity['funded']);
        $this->assertEquals(0, $activity['underwriting']);
    }

    #[Test]
    public function funded_activity_counts_deals_funded_before_the_audit_trail_began(): void
    {
        // The trail only starts on 2026-11-03; a deal funded before that has no
        // status event, but its funded date still says it was funded.
        DB::table('funding_application_audit_events')
            ->where('event', FundingApplicationAuditEvent::EVENT_STATUS_CHANGED)
            ->delete();

        $data = $this->data();

        $this->assertEquals(2, $data['pipeline']['activity']['funded']);
        $this->assertEquals($data['kpis']['funded']['deals'], $data['pipeline']['activity']['funded']);
    }

    #[Test]
    public function stalled_deals_are_open_ones_without_a_status_change_for_14_days(): void
    {
        $data = $this->data();

        $this->assertEquals(1, $data['attention']['stalled_deals']);
        $this->assertEquals(30000, $data['attention']['stalled_requested']);
        $this->assertCount(1, $data['stalled_deals']);
        $deal = $data['stalled_deals'][0];
        $this->assertSame(FundingApplication::UNDERWRITING_REVIEW, $deal['status']);
        $this->assertEquals(30000, $deal['requested_amount']);
        $this->assertSame(45, $deal['days_in_status']);
        $this->assertStringStartsWith('FUND-', $deal['application_number']);
    }

    #[Test]
    public function needs_attention_lists_what_waits_on_the_host(): void
    {
        $attention = $this->data()['attention'];

        $this->assertEquals(['amount' => 80, 'invoices' => 1], $attention['unpaid_over_30_days']);
        $this->assertEquals(['amount' => 200, 'invoices' => 1], $attention['failed_invoices']);
        $this->assertSame(1, $attention['partners_owing']);
        $this->assertSame(1, $attention['suspended_partners']);
        $this->assertSame(1, $attention['failed_payments']);
        $this->assertSame(0, $attention['disputed_payments']);
        $this->assertSame(1, $attention['manual_payments_to_approve']);
        $this->assertEquals(['amount' => 4000, 'deals' => 1], $attention['payouts_due']);
        $this->assertSame(1, $attention['unreviewed_embedded_applications']);
        $this->assertSame(1, $attention['pending_prequalifications']);
        $this->assertEquals(['partners' => 1, 'clients' => 0], $attention['trials_ending']);
    }

    #[Test]
    public function growth_counts_clients_leads_and_partners(): void
    {
        $data = $this->data();

        // Hosted and Partnered, plus one client per application (seven, all opened in Oct/Nov).
        $this->assertSame(Borrower::whereBetween('created_at', ['2026-11-01', '2026-12-01'])->count(), $data['kpis']['new_clients']['current']);
        $this->assertSame(Borrower::whereBetween('created_at', ['2026-10-01', '2026-11-01'])->count(), $data['kpis']['new_clients']['previous']);
        $this->assertEquals(['current' => 3, 'previous' => 2], $data['kpis']['new_leads']);
        $this->assertEquals(['current' => 1, 'total' => 2], $data['kpis']['active_partners']);

        $this->assertEquals([
            'total' => 2,
            'white_label' => 1,
            'certified' => 1,
            'new' => 2,
            'on_trial' => 1,
            'suspended' => 1,
        ], $data['partners']);
    }

    #[Test]
    public function lead_sources_use_the_recorded_source_and_other_ways_in_are_separate(): void
    {
        $sources = $this->data()['lead_sources'];

        $bySource = collect($sources['leads'])->pluck('count', 'source');
        $this->assertSame(1, $bySource[Lead::LEAD_SOURCE_WEBSITE]);
        $this->assertSame(1, $bySource[Lead::LEAD_SOURCE_REFERRAL_PARTNER]);
        $this->assertSame(1, $bySource['not_set']);
        $this->assertSame(0, $bySource[Lead::LEAD_SOURCE_COLD_OUTREACH]);

        $this->assertSame(1, $sources['prequalifications']);
        $this->assertSame(3, $sources['embedded_applications']);
    }

    #[Test]
    public function top_partners_rank_by_funded_volume_in_the_period(): void
    {
        $top = $this->data()['top_partners'];

        $this->assertCount(1, $top);
        $this->assertSame('Partner One', $top[0]['company_name']);
        $this->assertEquals(2, $top[0]['leads']);
        $this->assertEquals(1, $top[0]['funded_deals']);
        $this->assertEquals(100000, $top[0]['funded_amount']);
        $this->assertEquals(4000, $top[0]['commission_share']);
        $this->assertEquals(0, $top[0]['commission_paid']);
    }

    #[Test]
    public function the_charts_cover_the_six_months_up_to_the_period(): void
    {
        $months = collect($this->data()['monthly'])->keyBy('month');

        $this->assertSame(['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'], $months->keys()->all());
        $this->assertEquals(['month' => '2026-11', 'funded' => 150000, 'cash' => 1550], $months['2026-11']);
        $this->assertEquals(['month' => '2026-10', 'funded' => 20000, 'cash' => 400], $months['2026-10']);
        $this->assertEquals(0, $months['2026-06']['funded']);
    }

    #[Test]
    public function last_month_moves_every_window_back(): void
    {
        $data = $this->data(['period' => 'last_month']);

        $this->assertSame('2026-10-01', $data['period']['from']);
        $this->assertSame('2026-09-01', $data['period']['previous_from']);
        $this->assertEquals(['current' => 400, 'previous' => 0], $data['kpis']['cash_collected']);
        $this->assertEquals(['amount' => 20000, 'deals' => 1, 'previous_amount' => 0], $data['kpis']['funded']);
    }
}
