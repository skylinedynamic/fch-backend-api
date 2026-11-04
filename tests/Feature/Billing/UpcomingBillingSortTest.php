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
 * Defect (BUG-009, PP-BILL-05): sorting Upcoming Billings by NAME did not
 * change the order. The sort read the partner's name only, but a client's row
 * shows the client's own name - on a white-label partner's list every row is a
 * client, so every row sorted on NULL. The join it used also let the partner's
 * id and status overwrite the subscription's own on each row.
 */
#[CoversClass(BillingService::class)]
class UpcomingBillingSortTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Partner $host;

    private Partner $wanda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->wanda = $this->makeWhiteLabelPartner(['full_name' => 'Wanda Wells']);
    }

    private function subscribe(string $entityType, int $entityId): Subscription
    {
        return Subscription::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'installment_count' => 3,
            'installments_paid' => 1,
            'payable_amount' => 300,
            'total_subscription_amount' => 900,
            'subscription_amount_per_month' => 300,
            'current_phase' => Subscription::PHASE_INSTALLMENT,
            'subscription_end_date' => now()->addDays(3)->toDateString(),
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    private function clientOf(Partner $partner, string $first, string $last): Subscription
    {
        return $this->subscribe(Borrower::class, $this->makeBorrower($partner, ['first_name' => $first, 'last_name' => $last])->id);
    }

    private function rows(User $viewer, string $order, string $field = 'partner_name'): array
    {
        $this->actingAsUser($viewer);

        return $this->postJson('/api/v1/common/billing/fetch-upcoming', [
            'page' => 1,
            'per_page' => 10,
            'sort_field' => $field,
            'sort_order' => $order,
        ])->assertOk()->json('data');
    }

    #[Test]
    public function a_white_label_partner_sorts_their_clients_by_name(): void
    {
        $this->clientOf($this->wanda, 'Zia', 'Fuentes');
        $this->clientOf($this->wanda, 'Kim', 'Saunders');
        $wandaLogin = $this->makePartnerAdmin($this->wanda);

        $this->assertSame(['Kim Saunders', 'Zia Fuentes'], array_column($this->rows($wandaLogin, 'asc'), 'subscriber_name'));
        $this->assertSame(['Zia Fuentes', 'Kim Saunders'], array_column($this->rows($wandaLogin, 'desc'), 'subscriber_name'));
    }

    #[Test]
    public function the_host_sorts_partners_and_its_own_clients_together(): void
    {
        $this->subscribe(Partner::class, $this->wanda->id);
        $this->clientOf($this->host, 'Zia', 'Fuentes');
        $this->clientOf($this->host, 'Kim', 'Saunders');
        $admin = $this->makePartnerAdmin($this->host);

        $this->assertSame(['Kim Saunders', 'Wanda Wells', 'Zia Fuentes'], array_column($this->rows($admin, 'asc'), 'subscriber_name'));
        $this->assertSame(['Zia Fuentes', 'Wanda Wells', 'Kim Saunders'], array_column($this->rows($admin, 'desc'), 'subscriber_name'));
    }

    #[Test]
    public function sorting_by_name_keeps_each_rows_own_subscription_id_and_status(): void
    {
        $subscription = $this->subscribe(Partner::class, $this->wanda->id);
        $this->wanda->update(['status' => Partner::STATUS_SUSPENDED]);
        $admin = $this->makePartnerAdmin($this->host);

        $row = $this->rows($admin, 'asc')[0];

        $this->assertSame($subscription->id, $row['subscription_id']);
        $this->assertSame(Subscription::STATUS_ACTIVE, $row['status']);
    }

    /**
     * BUG-012: the list goes by upcoming billing date, the soonest charge first.
     * A subscriber still awaiting a first payment has no date yet and comes
     * after every dated row, not before them.
     */
    #[Test]
    public function the_soonest_billing_date_comes_first_and_undated_rows_last(): void
    {
        $later = $this->clientOf($this->host, 'Later', 'Charge');
        $later->update(['subscription_end_date' => now()->addDays(5)->toDateString()]);
        $undated = $this->clientOf($this->host, 'Awaiting', 'First');
        $undated->update(['subscription_end_date' => null]);
        $soonest = $this->clientOf($this->host, 'Soonest', 'Charge');
        $soonest->update(['subscription_end_date' => now()->addDay()->toDateString()]);
        $admin = $this->makePartnerAdmin($this->host);

        $this->assertSame(
            ['Soonest Charge', 'Later Charge', 'Awaiting First'],
            array_column($this->rows($admin, 'asc', 'upcoming_billing_date'), 'subscriber_name')
        );
    }

    /**
     * BUG-012 (reopened): AMOUNT sorts on the amount each row shows. The sort
     * picked its own column per phase, so an enrolling client's down payment
     * sorted as their payable amount and the order matched nothing on screen.
     */
    #[Test]
    public function amount_sorts_on_the_amount_each_row_shows(): void
    {
        $this->clientOf($this->host, 'Monthly', 'Three Hundred');
        $downPayment = $this->clientOf($this->host, 'Down', 'Payment');
        $downPayment->update([
            'current_phase' => Subscription::PHASE_ENROLLMENT,
            'installments_paid' => 0,
            'down_payment' => 1000,
            'payable_amount' => 4,
        ]);
        $platformFee = $this->subscribe(Partner::class, $this->wanda->id);
        $platformFee->update(['current_phase' => Subscription::PHASE_PLATFORM_FEE, 'monthly_platform_fee' => 50, 'payable_amount' => 7999]);
        $noMonthly = $this->clientOf($this->host, 'Falls', 'Through');
        $noMonthly->update(['current_phase' => Subscription::PHASE_ENROLLMENT, 'subscription_amount_per_month' => null, 'payable_amount' => 6997]);
        $admin = $this->makePartnerAdmin($this->host);

        $this->assertSame([50, 300, 1000, 6997], array_column($this->rows($admin, 'asc', 'upcoming_billing_amount'), 'upcoming_billing_amount'));
        $this->assertSame([6997, 1000, 300, 50], array_column($this->rows($admin, 'desc', 'upcoming_billing_amount'), 'upcoming_billing_amount'));
    }

    /**
     * BUG-049: a client whose next installment is the last one is billed the
     * adjusted amount (6696.70 over 12: 558.06 a month, the last 558.04). The
     * list shows, sorts on and totals what the cron will charge.
     */
    #[Test]
    public function a_last_installment_shows_sorts_and_totals_at_its_adjusted_amount(): void
    {
        $lastOne = $this->clientOf($this->host, 'Last', 'One');
        $lastOne->update([
            'installment_type' => 1,
            'installment_count' => 12,
            'installments_paid' => 11,
            'total_subscription_amount' => 6696.70,
            'subscription_amount_per_month' => 558.06,
        ]);
        $between = $this->clientOf($this->host, 'In', 'Between');
        $between->update(['installment_type' => 1, 'subscription_amount_per_month' => 558.05]);
        $admin = $this->makePartnerAdmin($this->host);
        $this->actingAsUser($admin);

        $response = $this->postJson('/api/v1/common/billing/fetch-upcoming', [
            'page' => 1,
            'per_page' => 10,
            'sort_field' => 'upcoming_billing_amount',
            'sort_order' => 'asc',
        ])->assertOk();

        $this->assertSame(['Last One', 'In Between'], array_column($response->json('data'), 'subscriber_name'));
        $this->assertEquals([558.04, 558.05], array_column($response->json('data'), 'upcoming_billing_amount'));
        $this->assertEquals(1116.09, $response->json('summary.scheduled_total'));
    }

    /**
     * BUG-012 (reopened): OUTSTANDING sorts on the unpaid invoices' total it
     * shows; a paid or deleted invoice is not owed and does not count.
     */
    #[Test]
    public function outstanding_sorts_on_the_unpaid_total_each_row_shows(): void
    {
        $owesNothing = $this->clientOf($this->host, 'Owes', 'Nothing');
        $this->invoice($owesNothing, 5000, Invoice::STATUS_PAID);
        $owesMost = $this->clientOf($this->host, 'Owes', 'Most');
        $this->invoice($owesMost, 700);
        $this->invoice($owesMost, 2000);
        $owesSome = $this->clientOf($this->host, 'Owes', 'Some');
        $this->invoice($owesSome, 999);
        $this->invoice($owesSome, 9000)->delete();
        $admin = $this->makePartnerAdmin($this->host);

        $this->assertSame([0, 999, 2700], array_column($this->rows($admin, 'asc', 'outstanding_amount'), 'outstanding_amount'));
        $this->assertSame([2700, 999, 0], array_column($this->rows($admin, 'desc', 'outstanding_amount'), 'outstanding_amount'));
    }

    private function invoice(Subscription $subscription, float $amount, string $status = Invoice::STATUS_UNPAID): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-' . str_pad((string) (Invoice::count() + 1), 6, '0', STR_PAD_LEFT),
            'subscription_id' => $subscription->id,
            'entity_type' => $subscription->entity_type,
            'entity_id' => $subscription->entity_id,
            'payment_amount' => $amount,
            'month_count' => 1,
            'status' => $status,
            'type' => 'Subscription',
            'currency' => 'USD',
        ]);
    }
}
