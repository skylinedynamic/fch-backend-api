<?php

namespace Tests\Feature\MySubscription;

use App\Http\Controllers\Api\V1\FCH\Common\MySubscription\MySubscriptionController;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * A partner's My Subscription > Recent Payments History lists what the partner
 * paid the host: its own plan (enrollment, down payment, installment, platform
 * fee), its staff seats and the borrower fee for each client it activated -
 * each with its invoice type. A commission paid TO the partner is not one of
 * its payments, nor is anything another partner paid.
 */
#[CoversClass(MySubscriptionController::class)]
class MySubscriptionRecentPaymentsTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Partner $partner;
    private User $admin;
    private Subscription $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->admin = $this->makePartnerAdmin($this->partner);
        $this->plan = $this->subscription(Partner::class, $this->partner->id);
    }

    private function subscription(string $entityType, int $entityId): Subscription
    {
        return Subscription::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'installment_count' => 1,
            'installments_paid' => 0,
            'payable_amount' => 500,
            'total_subscription_amount' => 500,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    /** A payment the partner made (or received) for an invoice of $type. */
    private function paid(string $type, ?Subscription $subscription, ?Partner $partner = null, array $invoice = []): Payment
    {
        $partner ??= $this->partner;
        $created = Invoice::create(array_merge([
            'invoice_id' => 'INV-' . strtoupper(uniqid()),
            'partner_id' => $partner->id,
            'subscription_id' => $subscription?->id,
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'payment_amount' => 10,
            'status' => Invoice::STATUS_PAID,
            'type' => $type,
            'is_partner_paying' => true,
        ], $invoice));

        return Payment::create([
            'partner_id' => $partner->id,
            'invoice_id' => $created->id,
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'transaction_id' => 'TX-' . uniqid(),
            'amount' => 10,
            'currency' => 'USD',
            'status' => Payment::STATUS_SUCCEEDED,
            'transaction_date' => now(),
            'is_environment' => false,
        ]);
    }

    #[Test]
    public function recent_payments_are_the_partners_plan_seat_and_borrower_fee_payments_with_their_type(): void
    {
        $enrollment = $this->paid(Invoice::INVOICE_TYPE_ENROLLMENT, $this->plan);
        $installment = $this->paid(Invoice::INVOICE_TYPE_INSTALLMENT, $this->plan);
        $platformFee = $this->paid(Invoice::INVOICE_TYPE_PLATFORM_FEE, $this->plan);

        $seatHolder = $this->makeStaffUser($this->partner);
        $seat = $this->paid(Invoice::INVOICE_TYPE_USER_SEAT, $this->subscription(User::class, $seatHolder->id));

        $client = $this->makeBorrower($this->partner);
        $borrowerFee = $this->paid(Invoice::INVOICE_TYPE_BORROWER_FEE, $this->subscription(Borrower::class, $client->id), null, ['borrower_id' => $client->id]);

        $payout = $this->paid(Invoice::INVOICE_TYPE_COMMISSION_PAYOUT, null, null, ['is_partner_paying' => false]);
        $other = $this->paid(Invoice::INVOICE_TYPE_ENROLLMENT, null, $this->makeWhiteLabelPartner());

        $this->actingAsUser($this->admin);
        $rows = collect($this->getJson('/api/v1/common/my-subscription')->assertOk()->json('data.recent_payments'))->keyBy('id');

        $this->assertEqualsCanonicalizing(
            [$enrollment->id, $installment->id, $platformFee->id, $seat->id, $borrowerFee->id],
            $rows->keys()->all()
        );
        $this->assertNotContains($payout->id, $rows->keys()->all());
        $this->assertNotContains($other->id, $rows->keys()->all());

        $this->assertSame(Invoice::INVOICE_TYPE_ENROLLMENT, $rows[$enrollment->id]['invoice_type']);
        $this->assertSame(Invoice::INVOICE_TYPE_USER_SEAT, $rows[$seat->id]['invoice_type']);
        $this->assertSame(Invoice::INVOICE_TYPE_BORROWER_FEE, $rows[$borrowerFee->id]['invoice_type']);
    }

    /**
     * BUG-194 retest: Recent Payments History still named the $800 fee "Borrower
     * Fee". The API now sends the name to show beside the stored type.
     */
    #[Test]
    public function recent_payments_name_the_borrower_fee_a_client_activation_fee(): void
    {
        $client = $this->makeBorrower($this->partner);
        $borrowerFee = $this->paid(Invoice::INVOICE_TYPE_BORROWER_FEE, $this->subscription(Borrower::class, $client->id), null, ['borrower_id' => $client->id]);
        $seatHolder = $this->makeStaffUser($this->partner);
        $seat = $this->paid(Invoice::INVOICE_TYPE_USER_SEAT, $this->subscription(User::class, $seatHolder->id));

        $this->actingAsUser($this->admin);
        $rows = collect($this->getJson('/api/v1/common/my-subscription')->assertOk()->json('data.recent_payments'))->keyBy('id');

        $this->assertSame('Client Activation Fee', $rows[$borrowerFee->id]['invoice_type_label']);
        $this->assertSame(Invoice::INVOICE_TYPE_BORROWER_FEE, $rows[$borrowerFee->id]['invoice_type']);
        $this->assertSame('User Seat', $rows[$seat->id]['invoice_type_label']);
    }
}
