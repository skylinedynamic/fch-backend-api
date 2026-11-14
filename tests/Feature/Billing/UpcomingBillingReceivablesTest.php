<?php

namespace Tests\Feature\Billing;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Services\FCH\Common\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Upcoming Billings lists the money owed TO the viewer: bills due within the
 * window (config subscription.upcoming_window_days) plus every open one -
 * Unpaid, or Processing (a manual payment awaiting approval).
 *
 * - A partner is paid only by its own clients (enrollment / installment). The
 *   borrower fee it owes the host for a client is never its receivable, even
 *   though that invoice sits on the client's subscription.
 * - The host is paid by its own clients, and by partners: their plan
 *   (enrollment, installment, platform fee), their staff seats and the borrower
 *   fee they pay to activate a client. An unpaid borrower fee is the partner's
 *   row, naming the client it is for.
 */
#[CoversClass(BillingService::class)]
class UpcomingBillingReceivablesTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Partner $host;
    private Partner $wanda;
    private User $hostAdmin;
    private User $wandaAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->wanda = $this->makeWhiteLabelPartner(['full_name' => 'Wanda Wells']);
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
        $this->wandaAdmin = $this->makePartnerAdmin($this->wanda);
    }

    private function subscribe(string $entityType, int $entityId, array $attributes = []): Subscription
    {
        return Subscription::create(array_merge([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'installment_type' => 1,
            'installment_count' => 3,
            'installments_paid' => 1,
            'payable_amount' => 300,
            'total_subscription_amount' => 900,
            'subscription_amount_per_month' => 300,
            'current_phase' => Subscription::PHASE_INSTALLMENT,
            'subscription_end_date' => now()->addDays(3)->toDateString(),
            'status' => Subscription::STATUS_ACTIVE,
        ], $attributes));
    }

    private function client(Partner $partner, string $first, array $subscription = []): Subscription
    {
        $borrower = $this->makeBorrower($partner, ['first_name' => $first, 'last_name' => 'Client']);

        return $this->subscribe(Borrower::class, $borrower->id, $subscription);
    }

    /** The client's own bill (enrollment, installment). */
    private function clientInvoice(Subscription $subscription, string $status, float $amount = 500, string $type = Invoice::INVOICE_TYPE_ENROLLMENT): Invoice
    {
        $borrower = Borrower::find($subscription->entity_id);

        return Invoice::create([
            'invoice_id' => 'INV-T' . uniqid(),
            'partner_id' => $borrower->partner_id,
            'borrower_id' => $borrower->id,
            'subscription_id' => $subscription->id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'payment_amount' => $amount,
            'status' => $status,
            'type' => $type,
            'is_partner_paying' => false,
        ]);
    }

    /** The borrower fee the partner pays the host for the client (as EnrollmentService raises it). */
    private function borrowerFee(Subscription $clientSubscription, string $status = Invoice::STATUS_UNPAID): Invoice
    {
        $borrower = Borrower::find($clientSubscription->entity_id);

        return Invoice::create([
            'invoice_id' => 'INV-F' . uniqid(),
            'partner_id' => $borrower->partner_id,
            'borrower_id' => $borrower->id,
            'subscription_id' => $clientSubscription->id,
            'entity_type' => Partner::class,
            'entity_id' => $borrower->partner_id,
            'payment_amount' => 800,
            'status' => $status,
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
            'is_partner_paying' => true,
        ]);
    }

    private function fetch(User $viewer, array $filters = []): array
    {
        $this->actingAsUser($viewer);

        return $this->postJson('/api/v1/common/billing/fetch-upcoming', $filters + ['page' => 1, 'per_page' => 50])
            ->assertOk()->json();
    }

    private function rowsById(array $response): array
    {
        return collect($response['data'])->keyBy('id')->all();
    }

    #[Test]
    public function a_partner_lists_what_its_clients_owe_it_and_never_the_borrower_fee(): void
    {
        $dueSoon = $this->client($this->wanda, 'Ann');
        $this->borrowerFee($dueSoon);                                    // owed by Wanda, not to her
        $later = $this->client($this->wanda, 'Ben', ['subscription_end_date' => now()->addDays(30)->toDateString()]);
        $unpaidSignup = $this->client($this->wanda, 'Cat', [
            'status' => Subscription::STATUS_PENDING, 'subscription_end_date' => null, 'installments_paid' => 0,
        ]);
        $this->clientInvoice($unpaidSignup, Invoice::STATUS_UNPAID, 500);
        $onlyFee = $this->client($this->wanda, 'Dee', ['subscription_end_date' => now()->addDays(30)->toDateString()]);
        $this->borrowerFee($onlyFee);
        $this->subscribe(Partner::class, $this->wanda->id);              // Wanda's own plan: she pays it

        $response = $this->fetch($this->wandaAdmin);
        $rows = $this->rowsById($response);

        $this->assertEqualsCanonicalizing([$dueSoon->id, $unpaidSignup->id], array_keys($rows));
        $this->assertNotContains($later->id, array_keys($rows));
        $this->assertSame(0.0, (float) $rows[$dueSoon->id]['outstanding_amount']);
        $this->assertSame(500.0, (float) $rows[$unpaidSignup->id]['outstanding_amount']);
        $this->assertSame(500.0, (float) $response['summary']['outstanding_total']);
    }

    #[Test]
    public function the_host_lists_its_clients_partner_plans_seats_and_unpaid_borrower_fees(): void
    {
        $hostClient = $this->client($this->host, 'Hal');
        $partnerPlan = $this->subscribe(Partner::class, $this->wanda->id);
        $cert = $this->makeWhiteLabelPartner(['full_name' => 'Cert Co']);
        $platformFee = $this->subscribe(Partner::class, $cert->id, [
            'current_phase' => Subscription::PHASE_PLATFORM_FEE, 'monthly_platform_fee' => 399,
        ]);
        $seat = $this->subscribe(User::class, $this->makeStaffUser($this->wanda)->id, [
            'current_phase' => Subscription::PHASE_USER_SEAT, 'per_seat_amount' => 49,
        ]);
        $feeOwed = $this->client($this->wanda, 'Dee', ['status' => Subscription::STATUS_PENDING, 'subscription_end_date' => null]);
        $fee = $this->borrowerFee($feeOwed);
        $feePaid = $this->client($this->wanda, 'Eve');
        $this->borrowerFee($feePaid, Invoice::STATUS_PAID);
        $partnersClient = $this->client($this->wanda, 'Fay');            // pays Wanda, not the host
        $this->clientInvoice($partnersClient, Invoice::STATUS_UNPAID);

        $response = $this->fetch($this->hostAdmin);
        $rows = $this->rowsById($response);

        $this->assertEqualsCanonicalizing(
            [$hostClient->id, $partnerPlan->id, $platformFee->id, $seat->id, $feeOwed->id],
            array_keys($rows)
        );

        $feeRow = $rows[$feeOwed->id];
        $this->assertSame('Partner', $feeRow['subscriber_type']);
        $this->assertSame('Wanda Wells', $feeRow['subscriber_name']);
        // BUG-194: the fee is named "Client Activation Fee", as on Plan & Fee.
        $this->assertSame('Client Activation Fee', $feeRow['upcoming_billing_type']);
        $this->assertSame('Dee Client', $feeRow['for_client_name']);
        $this->assertSame($fee->id, $feeRow['fee_invoice_id']);
        $this->assertSame(800.0, (float) $feeRow['upcoming_billing_amount']);
        $this->assertSame(800.0, (float) $feeRow['outstanding_amount']);
        $this->assertSame(Invoice::STATUS_UNPAID, $feeRow['status']);
        $this->assertFalse($feeRow['is_billing_paused']);

        $this->assertSame('Platform Fee', $rows[$platformFee->id]['upcoming_billing_type']);
        $this->assertSame(800.0, (float) $response['summary']['outstanding_total']);
    }

    #[Test]
    public function a_manual_payment_awaiting_approval_still_counts_as_owed(): void
    {
        $hostClient = $this->client($this->host, 'Hal', [
            'status' => Subscription::STATUS_PENDING, 'subscription_end_date' => null, 'installments_paid' => 0,
        ]);
        $this->clientInvoice($hostClient, Invoice::STATUS_PROCESSING, 250);
        $feeOwed = $this->client($this->wanda, 'Dee', ['subscription_end_date' => now()->addDays(30)->toDateString()]);
        $this->borrowerFee($feeOwed, Invoice::STATUS_PROCESSING);

        $rows = $this->rowsById($this->fetch($this->hostAdmin));

        $this->assertSame(250.0, (float) $rows[$hostClient->id]['outstanding_amount']);
        $this->assertSame(800.0, (float) $rows[$feeOwed->id]['outstanding_amount']);
        $this->assertSame(Invoice::STATUS_PROCESSING, $rows[$feeOwed->id]['status']);

        $wandaClient = $this->client($this->wanda, 'Ivy', ['subscription_end_date' => now()->addDays(30)->toDateString()]);
        $this->clientInvoice($wandaClient, Invoice::STATUS_PROCESSING, 300, Invoice::INVOICE_TYPE_INSTALLMENT);
        $this->assertSame(300.0, (float) $this->rowsById($this->fetch($this->wandaAdmin))[$wandaClient->id]['outstanding_amount']);
    }

    #[Test]
    public function a_borrower_fee_row_follows_the_partner_filters_views_and_sorts(): void
    {
        $hostClient = $this->client($this->host, 'Hal');
        $feeOwed = $this->client($this->wanda, 'Dee', ['subscription_end_date' => now()->addDays(30)->toDateString()]);
        $this->borrowerFee($feeOwed);

        $ids = fn (array $filters) => array_column($this->fetch($this->hostAdmin, $filters)['data'], 'id');

        $this->assertSame([$feeOwed->id], $ids(['entity_type' => User::PARTNER]));
        $this->assertSame([$hostClient->id], $ids(['entity_type' => User::BORROWER]));
        // Owed now and not collected: overdue, never "scheduled".
        $this->assertContains($feeOwed->id, $ids(['view' => 'overdue']));
        $this->assertNotContains($feeOwed->id, $ids(['view' => 'scheduled']));
        $this->assertNotContains($feeOwed->id, $ids(['view' => 'paused']));

        $this->assertSame([$feeOwed->id, $hostClient->id], $ids(['sort_field' => 'upcoming_billing_amount', 'sort_order' => 'desc']));
        $this->assertSame([$feeOwed->id, $hostClient->id], $ids(['sort_field' => 'outstanding_amount', 'sort_order' => 'desc']));
        // Sorted on the partner's name it shows ("Wanda Wells" after "Hal Client").
        $this->assertSame([$hostClient->id, $feeOwed->id], $ids(['sort_field' => 'subscriber_name', 'sort_order' => 'asc']));
    }

    /**
     * BUG-194: the row reads "Client Activation Fee", so the Billing Type search
     * must find it by that name, as well as by the old one.
     */
    #[Test]
    public function the_billing_type_search_finds_the_fee_by_its_new_name(): void
    {
        $hostClient = $this->client($this->host, 'Hal');
        $feeOwed = $this->client($this->wanda, 'Dee');
        $this->borrowerFee($feeOwed);

        $search = fn (string $value) => array_column($this->fetch($this->hostAdmin, ['filters' => ['upcoming_billing_type' => [
            'operator' => 'and',
            'constraints' => [['value' => $value, 'matchMode' => 'contains']],
        ]]])['data'], 'id');

        $this->assertSame([$feeOwed->id], $search('Client Activation Fee'));
        $this->assertSame([$feeOwed->id], $search('activation'));
        $this->assertSame([$feeOwed->id], $search('Borrower Fee'));
        $this->assertNotContains($hostClient->id, $search('Client Activation Fee'));
    }

    #[Test]
    public function a_borrower_fee_is_paused_while_the_partner_is_not_active(): void
    {
        $feeOwed = $this->client($this->wanda, 'Dee');
        $this->borrowerFee($feeOwed);
        $this->wanda->update(['status' => Partner::STATUS_SUSPENDED]);

        $row = $this->rowsById($this->fetch($this->hostAdmin))[$feeOwed->id];

        $this->assertTrue($row['is_billing_paused']);
        $this->assertSame('Partner is ' . Partner::STATUS_SUSPENDED, $row['billing_paused_reason']);
        $this->assertContains($feeOwed->id, array_column($this->fetch($this->hostAdmin, ['view' => 'paused'])['data'], 'id'));
    }
}
