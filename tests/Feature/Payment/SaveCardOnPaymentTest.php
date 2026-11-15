<?php

namespace Tests\Feature\Payment;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use App\Services\GlobalPayment\Drivers\StripePaymentDriver;
use App\Services\GlobalPayment\UnifiedPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStripeHttpClient;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * A card used to pay an invoice is kept as the payer's default payment method
 * only when the payer ticks "Save card as default payment method".
 *
 * It used to happen silently: the first card that paid became the payer's card
 * on file - for a partner, even a staff member's own card (BUG-202) - and on
 * Stripe every card that paid became the customer's default, which is the card
 * the subscription auto-charge uses.
 */
#[CoversClass(UnifiedPaymentService::class)]
#[CoversClass(StripePaymentDriver::class)]
class SaveCardOnPaymentTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const PAY = '/api/v1/borrower/process-payment';

    private Partner $partner;
    private User $borrowerUser;
    private PaymentProvider $stripe;
    private FakeStripeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->http = FakeStripeHttpClient::install();

        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->borrowerUser = $this->makeBorrowerUser($this->partner, ['status' => Borrower::STATUS_ONBOARDING]);

        $this->stripe = PaymentProvider::create(['name' => PaymentProvider::STRIPE, 'display_name' => 'Stripe', 'status' => true]);

        app(PaymentMethodConfigurationService::class)->updateOrCreateConfig($this->partner->id, [
            'payment_provider_id' => $this->stripe->id,
            'payment_mode' => PaymentProvider::MODE_SANDBOX,
            'status' => true,
            'is_default' => true,
            'configuration' => [
                'sandbox' => ['publishable_key' => 'pk_test_save', 'secret_key' => 'sk_test_save'],
                'production' => ['publishable_key' => 'pk_live_save', 'secret_key' => 'sk_live_save'],
            ],
        ]);

        $this->actingAsUser($this->borrowerUser);
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    private function borrower(): Borrower
    {
        return Borrower::findOrFail($this->borrowerUser->entity_id);
    }

    private function invoice(): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $this->partner->id,
            'borrower_id' => $this->borrowerUser->entity_id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrowerUser->entity_id,
            'customer_name' => $this->borrowerUser->name,
            'email' => $this->borrowerUser->email,
            'payment_amount' => 150.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
        ]);
    }

    private function pay(Invoice $invoice, array $extra = [])
    {
        return $this->postJson(self::PAY, array_merge([
            'invoice_id' => $invoice->id,
            'payment_method_id' => $this->stripe->id,
            'payment_token' => 'pm_fake',
        ], $extra));
    }

    /** Whether the card was made the Stripe customer's default. */
    private function stripeDefaultWasSet(): bool
    {
        foreach ($this->http->requestsTo('post', 'customers/cus_fake') as $request) {
            if (isset($request['params']['invoice_settings']['default_payment_method'])) {
                return true;
            }
        }

        return false;
    }

    private function cardOnFile(string $cardNo): void
    {
        $this->borrower()->update([
            'default_payment_method' => PaymentProvider::STRIPE,
            'stripe_customer_id' => [
                'customer_id' => 'cus_fake',
                'name' => 'Old Card',
                'card_no' => '1111',
                'payment_mode' => PaymentProvider::MODE_SANDBOX,
            ],
        ]);
    }

    #[Test]
    public function paying_without_ticking_the_box_saves_nothing(): void
    {
        $invoice = $this->invoice();

        $this->pay($invoice)->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $borrower = $this->borrower();
        $this->assertEmpty($borrower->stripe_customer_id['card_no'] ?? null);
        $this->assertNotSame(PaymentProvider::STRIPE, $borrower->default_payment_method);
        $this->assertFalse($this->stripeDefaultWasSet(), 'the card is not made the Stripe default');
    }

    #[Test]
    public function paying_without_ticking_the_box_keeps_the_card_already_on_file(): void
    {
        $this->cardOnFile('1111');

        $this->pay($this->invoice(), ['save_card' => false])->assertOk();

        $stored = $this->borrower()->stripe_customer_id;
        $this->assertSame('1111', $stored['card_no']);
        $this->assertSame('Old Card', $stored['name']);
        $this->assertFalse($this->stripeDefaultWasSet());
    }

    #[Test]
    public function ticking_the_box_saves_the_card_as_the_default(): void
    {
        $this->pay($this->invoice(), ['save_card' => true])->assertOk();

        $borrower = $this->borrower();
        $this->assertSame(PaymentProvider::STRIPE, $borrower->default_payment_method);
        $this->assertSame('cus_fake', $borrower->stripe_customer_id['customer_id']);
        $this->assertSame('**** **** **** 4242', $borrower->stripe_customer_id['card_no']);
        $this->assertSame(PaymentProvider::MODE_SANDBOX, $borrower->stripe_customer_id['payment_mode']);
        $this->assertTrue($this->stripeDefaultWasSet(), 'the card is made the Stripe default');
    }

    #[Test]
    public function ticking_the_box_replaces_the_card_already_on_file(): void
    {
        $this->cardOnFile('1111');

        $this->pay($this->invoice(), ['save_card' => true])->assertOk();

        $stored = $this->borrower()->stripe_customer_id;
        $this->assertSame('**** **** **** 4242', $stored['card_no']);
        $this->assertSame('A Cardholder', $stored['name']);
        $this->assertTrue($this->stripeDefaultWasSet());
    }

    #[Test]
    public function a_card_that_needs_authentication_is_saved_only_when_the_box_was_ticked(): void
    {
        $this->http->on('post', 'payment_intents', [
            'id' => 'pi_3ds', 'object' => 'payment_intent', 'status' => 'requires_action',
            'client_secret' => 'pi_3ds_secret', 'customer' => 'cus_fake', 'payment_method' => 'pm_fake',
            'next_action' => ['type' => 'use_stripe_sdk'],
        ]);
        $this->http->on('get', 'payment_intents/pi_3ds', [
            'id' => 'pi_3ds', 'object' => 'payment_intent', 'status' => 'succeeded',
            'client_secret' => 'pi_3ds_secret', 'customer' => 'cus_fake', 'payment_method' => 'pm_fake',
        ]);

        $paymentId = $this->pay($this->invoice())->assertOk()->json('data.payment_id');
        $this->postJson(self::PAY . '/confirm', ['payment_id' => $paymentId])->assertOk();

        $this->assertEmpty($this->borrower()->stripe_customer_id['card_no'] ?? null);
        $this->assertFalse($this->stripeDefaultWasSet());
    }

    #[Test]
    public function save_card_must_be_true_or_false(): void
    {
        $this->pay($this->invoice(), ['save_card' => 'sometimes'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['save_card']);
    }
}
