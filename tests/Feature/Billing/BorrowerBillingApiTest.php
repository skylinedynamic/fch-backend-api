<?php

namespace Tests\Feature\Billing;

use App\Http\Controllers\Api\V1\Borrower\Billing\BillingController;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Services\GlobalPayment\Stripe\StripeCustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The borrower portal's billing screen: the signed-in borrower's own
 * subscription, their default payment method and cancelling.
 */
#[CoversClass(BillingController::class)]
class BorrowerBillingApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->me = $this->makeBorrowerUser($this->partner);
    }

    private function subscribe(Borrower $borrower, array $attributes = []): Subscription
    {
        return Subscription::create(array_merge([
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'installment_count' => 1,
            'installments_paid' => 0,
            'payable_amount' => 300,
            'total_subscription_amount' => 300,
            'status' => Subscription::STATUS_ACTIVE,
        ], $attributes));
    }

    private function myBorrower(): Borrower
    {
        return Borrower::find($this->me->entity_id);
    }

    #[Test]
    public function a_borrower_reads_their_own_billing(): void
    {
        $this->subscribe($this->myBorrower());
        $this->actingAsUser($this->me);

        $this->getJson('/api/v1/borrower/billing-details')->assertOk()->assertJsonPath('status', true);
    }

    #[Test]
    public function the_billing_screen_shows_the_last_installment_adjusted_for_rounding(): void
    {
        // BUG-049: 6696.70 over 12 months is 558.06 a month; the last is 558.04.
        $installments = [
            'installment_type' => 1,
            'installment_count' => 12,
            'total_subscription_amount' => 6696.70,
            'subscription_amount_per_month' => 558.06,
        ];
        $this->subscribe($this->myBorrower(), $installments + ['installments_paid' => 11]);
        $this->actingAsUser($this->me);

        $this->getJson('/api/v1/borrower/billing-details')
            ->assertOk()
            ->assertJsonPath('data.subscription.subscription_amount_per_month', '$558.06')
            ->assertJsonPath('data.subscription.final_installment_amount', '$558.04');
    }

    /**
     * BUG-187 (same card on the borrower side): Current / Recent Bill shifted the
     * invoice's timestamp into the viewer's timezone, so an evening-UTC invoice
     * showed the next day. It now shows the invoice's own date.
     */
    #[Test]
    public function the_recent_bill_shows_the_invoices_own_date_whatever_the_viewers_timezone(): void
    {
        $borrower = $this->myBorrower();
        $subscription = $this->subscribe($borrower);
        $invoice = Invoice::create([
            'invoice_id' => 'INV-' . strtoupper(uniqid()),
            'partner_id' => $this->partner->id,
            'borrower_id' => $borrower->id,
            'subscription_id' => $subscription->id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            'payment_amount' => 300,
            'status' => Invoice::STATUS_PAID,
        ]);
        $invoice->forceFill(['created_at' => '2026-10-20 20:00:00'])->saveQuietly();
        $this->actingAsUser($this->me);

        $this->getJson('/api/v1/borrower/billing-details', ['X-Timezone' => 'Asia/Dhaka'])
            ->assertOk()
            ->assertJsonPath('data.recent_bill.invoice_id', $invoice->invoice_id)
            ->assertJsonPath('data.recent_bill.created_at', '2026-10-20');
    }

    #[Test]
    public function an_even_split_has_no_separate_last_installment(): void
    {
        $this->subscribe($this->myBorrower(), [
            'installment_type' => 1,
            'installment_count' => 3,
            'total_subscription_amount' => 900,
            'subscription_amount_per_month' => 300,
        ]);
        $this->actingAsUser($this->me);

        $this->getJson('/api/v1/borrower/billing-details')
            ->assertOk()
            ->assertJsonPath('data.subscription.final_installment_amount', null);
    }

    #[Test]
    public function a_borrower_cancels_only_their_own_subscription_once(): void
    {
        $mine = $this->subscribe($this->myBorrower());
        $theirs = $this->subscribe($this->makeBorrower($this->partner));
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/cancel-subscription')->assertOk();
        $this->postJson('/api/v1/borrower/cancel-subscription')->assertStatus(400);

        $this->assertSame(Subscription::STATUS_CANCELLED, $mine->fresh()->status);
        $this->assertSame(Subscription::STATUS_ACTIVE, $theirs->fresh()->status);
    }

    #[Test]
    public function with_no_subscription_there_is_nothing_to_cancel(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/cancel-subscription')->assertNotFound();
    }

    /**
     * A borrower can take their default payment method off: nothing is then
     * auto-charged until they set one again. Only the default goes - payments
     * already made stay, and nothing is removed at Stripe.
     */
    #[Test]
    public function a_borrower_removes_their_default_payment_method(): void
    {
        $borrower = $this->myBorrower();
        $this->subscribe($borrower);
        $borrower->update([
            'default_payment_method' => PaymentProvider::STRIPE,
            'stripe_customer_id' => ['customer_id' => 'cus_1', 'name' => 'Visa', 'card_no' => '4242', 'payment_mode' => 'test'],
        ]);
        $paid = Payment::create([
            'partner_id' => $this->partner->id, 'borrower_id' => $borrower->id, 'entity_type' => Borrower::class, 'entity_id' => $borrower->id,
            'transaction_id' => 'TX-KEEP', 'amount' => 300, 'currency' => 'USD', 'status' => Payment::STATUS_SUCCEEDED,
            'is_partner_paying' => false, 'transaction_date' => now(),
        ]);
        $other = $this->makeBorrower($this->partner, ['default_payment_method' => PaymentProvider::STRIPE]);
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/remove-default-payment-method')
            ->assertOk()
            ->assertJsonPath('data.default_payment_method', null);

        $borrower = $this->myBorrower();
        $this->assertNull($borrower->default_payment_method);
        $this->assertNull($borrower->stripe_customer_id, 'a saved customer is what auto-charge charges');
        $this->assertNotNull(Payment::find($paid->id), 'payments already made are not touched');
        $this->assertSame(PaymentProvider::STRIPE, $other->fresh()->default_payment_method);

        $this->getJson('/api/v1/borrower/billing-details')
            ->assertOk()
            ->assertJsonPath('data.borrower.default_payment_method', null)
            ->assertJsonPath('data.borrower.payment_provider', null);
    }

    /** A card saved before defaults were recorded still reads as Stripe. */
    #[Test]
    public function a_saved_card_with_no_recorded_default_still_shows_as_stripe(): void
    {
        $this->subscribe($this->myBorrower());
        $this->myBorrower()->update(['default_payment_method' => null, 'stripe_customer_id' => ['customer_id' => 'cus_old', 'card_no' => '4242']]);
        $this->actingAsUser($this->me);

        $this->getJson('/api/v1/borrower/billing-details')
            ->assertOk()
            ->assertJsonPath('data.borrower.default_payment_method', PaymentProvider::STRIPE);
    }

    /** A gateway the platform has and the borrower's partner has switched on (or off). */
    private function offerCash(bool $switchedOn = true): PaymentProvider
    {
        $cash = PaymentProvider::create(['name' => PaymentProvider::CASH, 'display_name' => 'Cash', 'status' => true]);
        app(\App\Services\FCH\Common\Setting\PaymentMethodConfigurationService::class)->updateOrCreateConfig($this->partner->id, [
            'payment_provider_id' => $cash->id,
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'status' => $switchedOn,
            'configuration' => [],
        ]);

        return $cash;
    }

    #[Test]
    public function a_borrower_switches_off_stripe(): void
    {
        $this->offerCash();
        $this->myBorrower()->update(['default_payment_method' => PaymentProvider::STRIPE, 'stripe_customer_id' => ['customer_id' => 'cus_1']]);
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/update-default-payment-method', ['provider_name' => PaymentProvider::CASH])
            ->assertOk()
            ->assertJsonPath('data.default_payment_method', PaymentProvider::CASH);
        $this->assertNull($this->myBorrower()->stripe_customer_id);
    }

    /**
     * Defect (O25): any provider_name string was stored as the default, whether
     * or not the borrower's partner offers that gateway.
     */
    #[Test]
    public function only_a_gateway_the_partner_has_switched_on_can_be_the_default(): void
    {
        $this->offerCash(switchedOn: false);
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/update-default-payment-method', ['provider_name' => 'Bitcoin'])
            ->assertUnprocessable()->assertJsonValidationErrors('provider_name');
        $this->postJson('/api/v1/borrower/update-default-payment-method', ['provider_name' => PaymentProvider::CASH])
            ->assertUnprocessable()->assertJsonValidationErrors('provider_name');

        $this->assertNotSame(PaymentProvider::CASH, $this->myBorrower()->default_payment_method);
    }

    /**
     * BUG-151: Stripe switched on but with no keys ("Active - Not configured")
     * could be made the default from Billing Details, so auto-charges would go
     * to a gateway that cannot take them - while Pay Now already hid it. Only
     * a gateway that can take payments can be the default.
     */
    #[Test]
    public function a_gateway_switched_on_but_not_set_up_cannot_be_the_default(): void
    {
        $stripe = PaymentProvider::create(['name' => PaymentProvider::STRIPE, 'display_name' => 'Stripe', 'status' => true]);
        app(\App\Services\FCH\Common\Setting\PaymentMethodConfigurationService::class)->updateOrCreateConfig($this->partner->id, [
            'payment_provider_id' => $stripe->id,
            'payment_mode' => PaymentProvider::MODE_SANDBOX,
            'status' => true,
            'configuration' => [],
        ]);
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/update-default-payment-method', ['provider_name' => PaymentProvider::STRIPE])
            ->assertUnprocessable()->assertJsonValidationErrors('provider_name');

        $this->assertNotSame(PaymentProvider::STRIPE, $this->myBorrower()->default_payment_method);
    }

    #[Test]
    public function a_provider_is_required(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/update-default-payment-method', [])->assertUnprocessable();
    }

    /**
     * Defect: as on the partner side (F36), a card Stripe refused was only
     * logged; the borrower's saved card was then overwritten with nothing and
     * the call reported success.
     */
    #[Test]
    public function a_card_stripe_refuses_leaves_the_saved_card_in_place(): void
    {
        $saved = ['customer_id' => 'cus_saved', 'name' => 'Visa', 'card_no' => '4242', 'payment_mode' => 'test'];
        $this->myBorrower()->update(['default_payment_method' => PaymentProvider::STRIPE, 'stripe_customer_id' => $saved]);
        $this->partialMock(Payment::class)->shouldReceive('configureStripeForPartner')->andReturn((object) ['payment_mode' => 'test']);
        $this->mock(StripeCustomerService::class)->shouldReceive('resolveCustomer')->andThrow(new \Exception('Your card was declined.'));
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/update-default-payment-method', [
            'provider_name' => PaymentProvider::STRIPE,
            'payment_method_id' => 'pm_declined',
        ])->assertStatus(422)->assertJsonPath('status', false);

        $this->assertSame($saved, $this->myBorrower()->stripe_customer_id);
    }
}
