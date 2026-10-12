<?php

namespace Tests\Feature\Billing;

use App\Http\Controllers\Api\V1\Borrower\Billing\BillingController;
use App\Models\Borrower\Borrower;
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
