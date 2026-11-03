<?php

namespace Tests\Feature\Payment;

use App\Http\Controllers\Api\V1\GlobalPayment\UnifiedPaymentController;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use App\Services\GlobalPayment\UnifiedPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStripeHttpClient;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-066: a card whose bank asks for 3-D Secure (an OTP) could not pay at all,
 * and the attempt left no trace for the client or the host.
 *
 * The flow now:
 *  1. Pay Now - Stripe answers `requires_action`. The attempt is kept as a
 *     PENDING payment and the browser gets the PaymentIntent's client secret.
 *  2. The browser runs the bank's challenge (stripe.handleNextAction).
 *  3. POST process-payment/confirm - the API reads the PaymentIntent back from
 *     Stripe and settles the same row: SUCCEEDED (invoice Paid) or FAILED.
 */
#[CoversClass(UnifiedPaymentController::class)]
#[CoversClass(UnifiedPaymentService::class)]
class CardAuthenticationCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

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
                'sandbox' => ['publishable_key' => 'pk_test_3ds', 'secret_key' => 'sk_test_3ds'],
                'production' => ['publishable_key' => 'pk_live_3ds', 'secret_key' => 'sk_live_3ds'],
            ],
        ]);

        $this->actingAsUser($this->borrowerUser);
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

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

    private static function intent(string $id, string $status, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'object' => 'payment_intent',
            'status' => $status,
            'client_secret' => $id . '_secret_abc',
            'customer' => 'cus_fake',
            'payment_method' => 'pm_fake',
            'amount' => 15000,
            'currency' => 'usd',
        ], $extra);
    }

    /** Stripe's answer when the issuer wants the cardholder to authenticate. */
    private function stripeAsksForAuthentication(string $id = 'pi_3ds'): void
    {
        $this->http->on('post', 'payment_intents', self::intent($id, 'requires_action', [
            'next_action' => ['type' => 'use_stripe_sdk'],
        ]));
    }

    /** What Stripe holds for the PaymentIntent after the challenge. */
    private function stripeReadsBack(string $id, string $status, array $extra = []): void
    {
        $this->http->on('get', "payment_intents/{$id}", self::intent($id, $status, $extra));
    }

    private function pay(Invoice $invoice, string $url = '/api/v1/borrower/process-payment')
    {
        return $this->postJson($url, [
            'invoice_id' => $invoice->id,
            'payment_method_id' => $this->stripe->id,
            'payment_token' => 'pm_card_authenticationRequired',
        ]);
    }

    private function confirm(int $paymentId, string $url = '/api/v1/borrower/process-payment/confirm')
    {
        return $this->postJson($url, ['payment_id' => $paymentId]);
    }

    /** Pay Now with a 3-D Secure card; returns the pending payment's id. */
    private function startAuthentication(Invoice $invoice, string $id = 'pi_3ds'): int
    {
        $this->stripeAsksForAuthentication($id);

        return (int) $this->pay($invoice)->assertOk()->json('data.payment_id');
    }

    // ── 1. Pay Now hands the challenge to the browser ───────────────────────

    #[Test]
    public function a_card_needing_authentication_is_handed_back_to_the_browser(): void
    {
        $this->stripeAsksForAuthentication();
        $invoice = $this->invoice();

        $response = $this->pay($invoice)->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', __('payment.authentication_required'))
            ->assertJsonPath('data.requires_action', true)
            ->assertJsonPath('data.client_secret', 'pi_3ds_secret_abc')
            ->assertJsonPath('data.status', Payment::STATUS_PENDING);

        $payment = Payment::findOrFail($response->json('data.payment_id'));
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame('pi_3ds', $payment->transaction_id);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'nothing is paid until the bank says so');
    }

    #[Test]
    public function the_pending_attempt_is_visible_to_the_client_and_the_host(): void
    {
        $invoice = $this->invoice();
        $paymentId = $this->startAuthentication($invoice);

        $payment = Payment::findOrFail($paymentId);
        $this->assertSame((int) $this->borrowerUser->entity_id, (int) $payment->borrower_id);
        $this->assertSame($this->partner->id, (int) $payment->partner_id);
        $this->assertTrue($payment->isAwaitingAuthentication());

        $ids = collect($this->postJson('/api/v1/borrower/payments/fetch-payments')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($paymentId, $ids->all());
    }

    #[Test]
    public function the_client_secret_is_not_stored(): void
    {
        $paymentId = $this->startAuthentication($this->invoice());

        $this->assertStringNotContainsString('_secret_', json_encode(Payment::findOrFail($paymentId)->toArray()));
    }

    // ── 2. Confirm settles the attempt ──────────────────────────────────────

    #[Test]
    public function a_successful_authentication_pays_the_invoice(): void
    {
        $invoice = $this->invoice();
        $paymentId = $this->startAuthentication($invoice);
        $this->stripeReadsBack('pi_3ds', 'succeeded');

        $this->confirm($paymentId)->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.status', Payment::STATUS_SUCCEEDED)
            ->assertJsonPath('data.payment_id', $paymentId);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count(), 'the pending row is settled, not duplicated');
        $payment = Payment::findOrFail($paymentId);
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->status);
        $this->assertFalse($payment->isAwaitingAuthentication());
        $this->assertSame(Borrower::STATUS_ACTIVE, Borrower::find($this->borrowerUser->entity_id)->status);
    }

    #[Test]
    public function a_failed_authentication_is_recorded_as_failed(): void
    {
        $invoice = $this->invoice();
        $paymentId = $this->startAuthentication($invoice);
        // Stripe's own wording for this is written for developers.
        $stripeWording = 'The provided PaymentMethod has failed authentication. You can provide payment_method_data or a new PaymentMethod to attempt to fulfill this PaymentIntent again.';
        $this->stripeReadsBack('pi_3ds', 'requires_payment_method', [
            'last_payment_error' => [
                'code' => 'payment_intent_authentication_failure',
                'message' => $stripeWording,
            ],
        ]);

        $this->confirm($paymentId)->assertStatus(400)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', __('payment.authentication_failed'));

        $payment = Payment::findOrFail($paymentId);
        $this->assertSame(Payment::STATUS_FAILED, $payment->status);
        $this->assertSame(Payment::FAILURE_SOURCE_CHECKOUT, $payment->details[Payment::DETAIL_FAILURE_SOURCE]);
        $this->assertSame('payment_intent_authentication_failure', $payment->details['code']);
        $this->assertSame($stripeWording, $payment->details['gateway_error'], 'Stripe\'s wording is kept for support');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    #[Test]
    public function a_card_declined_after_authentication_keeps_the_banks_reason(): void
    {
        $paymentId = $this->startAuthentication($this->invoice());
        $this->stripeReadsBack('pi_3ds', 'requires_payment_method', [
            'last_payment_error' => ['code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Your card has insufficient funds.'],
        ]);

        $this->confirm($paymentId)->assertStatus(400)
            ->assertJsonPath('message', 'Your card has insufficient funds.');
    }

    #[Test]
    public function an_unfinished_challenge_stays_pending(): void
    {
        $invoice = $this->invoice();
        $paymentId = $this->startAuthentication($invoice);
        $this->stripeReadsBack('pi_3ds', 'requires_action');

        $this->confirm($paymentId)->assertOk()
            ->assertJsonPath('message', __('payment.authentication_pending'))
            ->assertJsonPath('data.status', Payment::STATUS_PENDING);

        $this->assertSame(Payment::STATUS_PENDING, Payment::findOrFail($paymentId)->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    #[Test]
    public function confirming_twice_settles_once(): void
    {
        $invoice = $this->invoice();
        $paymentId = $this->startAuthentication($invoice);
        $this->stripeReadsBack('pi_3ds', 'succeeded');

        $this->confirm($paymentId)->assertOk();
        $this->confirm($paymentId)->assertOk()->assertJsonPath('data.status', Payment::STATUS_SUCCEEDED);

        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertCount(1, $this->http->requestsTo('get', 'payment_intents/pi_3ds'), 'a settled payment is not read from Stripe again');
    }

    // ── 3. Who may confirm what ─────────────────────────────────────────────

    #[Test]
    public function another_client_cannot_confirm_my_payment(): void
    {
        $paymentId = $this->startAuthentication($this->invoice());
        $this->stripeReadsBack('pi_3ds', 'succeeded');

        $this->actingAsUser($this->makeBorrowerUser($this->partner));

        $this->confirm($paymentId)->assertStatus(400)
            ->assertJsonPath('message', __('payment.unauthorized_payment_attempt'));

        $this->assertSame(Payment::STATUS_PENDING, Payment::findOrFail($paymentId)->status);
        $this->assertFalse($this->http->called('payment_intents/pi_3ds'), 'Stripe is not asked about someone else\'s payment');
    }

    #[Test]
    public function only_a_payment_awaiting_authentication_can_be_confirmed(): void
    {
        $invoice = $this->invoice();
        $manual = Payment::create([
            'invoice_id' => $invoice->id,
            'borrower_id' => $invoice->borrower_id,
            'partner_id' => $this->partner->id,
            'entity_type' => Borrower::class,
            'entity_id' => $invoice->entity_id,
            'transaction_id' => 'CASH-1',
            'amount' => 150,
            'currency' => 'USD',
            'status' => Payment::STATUS_PROCESSING,
            'is_manual' => true,
        ]);

        $this->confirm($manual->id)->assertStatus(400)
            ->assertJsonPath('message', __('payment.not_awaiting_authentication'));

        $this->assertSame(Payment::STATUS_PROCESSING, $manual->fresh()->status);
    }

    #[Test]
    public function confirm_needs_a_signed_in_user_and_a_payment_id(): void
    {
        $this->postJson('/api/v1/borrower/process-payment/confirm', [])->assertStatus(422)
            ->assertJsonValidationErrors(['payment_id']);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/borrower/process-payment/confirm', ['payment_id' => 1])->assertUnauthorized();
    }

    public static function confirmUrls(): array
    {
        return [
            'borrower portal' => ['/api/v1/borrower/process-payment', '/api/v1/borrower/process-payment/confirm'],
            'partner portal' => ['/api/v1/process-payment', '/api/v1/process-payment/confirm'],
            'payment/process' => ['/api/v1/payment/process', '/api/v1/payment/confirm'],
        ];
    }

    #[Test]
    #[DataProvider('confirmUrls')]
    public function every_portal_can_finish_an_authentication(string $payUrl, string $confirmUrl): void
    {
        $this->stripeAsksForAuthentication();
        $invoice = $this->invoice();
        $paymentId = (int) $this->pay($invoice, $payUrl)->assertOk()->json('data.payment_id');
        $this->stripeReadsBack('pi_3ds', 'succeeded');

        $this->confirm($paymentId, $confirmUrl)->assertOk()->assertJsonPath('data.status', Payment::STATUS_SUCCEEDED);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    // ── 4. A new attempt replaces an unfinished one ─────────────────────────

    #[Test]
    public function a_new_attempt_cancels_the_unfinished_one(): void
    {
        $invoice = $this->invoice();
        $oldId = $this->startAuthentication($invoice, 'pi_old');

        // The payer gives up on the challenge and pays with another card.
        $this->stripeReadsBack('pi_old', 'requires_action');
        $this->http->on('post', 'payment_intents/pi_old/cancel', self::intent('pi_old', 'canceled'));
        $this->http->on('post', 'payment_intents', self::intent('pi_new', 'succeeded'));

        $this->pay($invoice)->assertOk()->assertJsonPath('data.status', Payment::STATUS_SUCCEEDED);

        $this->assertCount(1, $this->http->requestsTo('post', 'payment_intents/pi_old/cancel'));
        $old = Payment::findOrFail($oldId);
        $this->assertSame(Payment::STATUS_FAILED, $old->status);
        $this->assertSame(__('payment.authentication_superseded'), $old->details['error']);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->where('status', Payment::STATUS_SUCCEEDED)->count());
    }

    #[Test]
    public function a_new_attempt_after_the_old_one_went_through_is_refused(): void
    {
        $invoice = $this->invoice();
        $oldId = $this->startAuthentication($invoice, 'pi_old');

        // The challenge was completed in another tab, but never confirmed here.
        $this->stripeReadsBack('pi_old', 'succeeded');
        $this->http->on('post', 'payment_intents', self::intent('pi_new', 'succeeded'));

        $this->pay($invoice)->assertStatus(400)->assertJsonPath('message', __('payment.only_unpaid_can_be_paid'));

        $this->assertSame(Payment::STATUS_SUCCEEDED, Payment::findOrFail($oldId)->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertCount(1, $this->http->requestsTo('post', 'payment_intents'), 'the card is not charged a second time');
    }
}
