<?php

namespace Tests\Feature\Payment;

use App\Console\Commands\SettleCardAuthentications;
use App\Jobs\ChargeSubscriptionInvoiceJob;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStripeHttpClient;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-066: a payer who closes the tab during the bank's 3-D Secure challenge
 * never reaches the confirm endpoint. payments:settle-card-authentications
 * picks those payments up: one the payer did authenticate is recorded and pays
 * the invoice; one left unfinished too long is cancelled at Stripe and FAILED.
 *
 * Meanwhile the auto-charge cron leaves the invoice alone, so a payer halfway
 * through the challenge is not charged a second time off-session.
 */
#[CoversClass(SettleCardAuthentications::class)]
class SettleCardAuthenticationsCommandTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $borrowerUser;
    private PaymentProvider $stripe;
    private FakeStripeHttpClient $http;
    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->http = FakeStripeHttpClient::install();

        $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->borrowerUser = $this->makeBorrowerUser($this->partner);

        $this->stripe = PaymentProvider::create(['name' => PaymentProvider::STRIPE, 'display_name' => 'Stripe', 'status' => true]);

        $this->accountId = app(PaymentMethodConfigurationService::class)->updateOrCreateConfig($this->partner->id, [
            'payment_provider_id' => $this->stripe->id,
            'payment_mode' => PaymentProvider::MODE_SANDBOX,
            'status' => true,
            'is_default' => true,
            'configuration' => [
                'sandbox' => ['publishable_key' => 'pk_test_3ds', 'secret_key' => 'sk_test_3ds'],
                'production' => ['publishable_key' => 'pk_live_3ds', 'secret_key' => 'sk_live_3ds'],
            ],
        ])->id;
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
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

    /** A Pay Now attempt left waiting on the bank's challenge, $minutesAgo. */
    private function awaiting(Invoice $invoice, string $intentId, int $minutesAgo): Payment
    {
        $payment = Payment::create([
            'partner_id' => $this->partner->id,
            'borrower_id' => $invoice->borrower_id,
            'invoice_id' => $invoice->id,
            'entity_type' => Borrower::class,
            'entity_id' => $invoice->entity_id,
            'transaction_id' => $intentId,
            'amount' => 150,
            'currency' => 'USD',
            'payment_from' => PaymentProvider::STRIPE,
            'payment_type' => PaymentProvider::TYPE_CARD,
            'status' => Payment::STATUS_PENDING,
            'is_environment' => Payment::environmentFromMode(PaymentProvider::MODE_SANDBOX),
            'details' => [
                Payment::DETAIL_FAILURE_SOURCE => Payment::FAILURE_SOURCE_CHECKOUT,
                Payment::DETAIL_AWAITING => Payment::AWAITING_AUTHENTICATION,
                Payment::DETAIL_GATEWAY_ACCOUNT => $this->accountId,
            ],
        ]);

        $payment->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $payment;
    }

    private function intent(string $id, string $status): array
    {
        return ['id' => $id, 'object' => 'payment_intent', 'status' => $status, 'customer' => 'cus_fake', 'payment_method' => 'pm_fake'];
    }

    #[Test]
    public function a_payment_authenticated_after_the_tab_closed_pays_the_invoice(): void
    {
        $invoice = $this->invoice();
        $payment = $this->awaiting($invoice, 'pi_closed_tab', 5);
        $this->http->on('get', 'payment_intents/pi_closed_tab', $this->intent('pi_closed_tab', 'succeeded'));

        $this->artisan('payments:settle-card-authentications')->assertSuccessful();

        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(['sk_test_3ds'], $this->http->apiKeysUsed(), 'read back from the account that made the charge');
    }

    #[Test]
    public function a_challenge_still_open_within_the_time_limit_is_left_alone(): void
    {
        $invoice = $this->invoice();
        $payment = $this->awaiting($invoice, 'pi_open', Payment::AUTHENTICATION_TIMEOUT_MINUTES - 5);
        $this->http->on('get', 'payment_intents/pi_open', $this->intent('pi_open', 'requires_action'));

        $this->artisan('payments:settle-card-authentications')->assertSuccessful();

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertCount(0, $this->http->requestsTo('post', 'payment_intents/pi_open/cancel'));
    }

    #[Test]
    public function a_challenge_left_open_too_long_is_cancelled_and_failed(): void
    {
        $invoice = $this->invoice();
        $payment = $this->awaiting($invoice, 'pi_stale', Payment::AUTHENTICATION_TIMEOUT_MINUTES + 1);
        $this->http->on('get', 'payment_intents/pi_stale', $this->intent('pi_stale', 'requires_action'));
        $this->http->on('post', 'payment_intents/pi_stale/cancel', $this->intent('pi_stale', 'canceled'));

        $this->artisan('payments:settle-card-authentications')->assertSuccessful();

        $this->assertCount(1, $this->http->requestsTo('post', 'payment_intents/pi_stale/cancel'));
        $failed = $payment->fresh();
        $this->assertSame(Payment::STATUS_FAILED, $failed->status);
        $this->assertSame(__('payment.authentication_expired'), $failed->details['error']);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    #[Test]
    public function a_stale_challenge_completed_at_the_last_moment_is_still_recorded(): void
    {
        $invoice = $this->invoice();
        $payment = $this->awaiting($invoice, 'pi_late', Payment::AUTHENTICATION_TIMEOUT_MINUTES + 1);
        // Read as waiting, but completed before the cancel landed: Stripe refuses to cancel it.
        $this->http->on('get', 'payment_intents/pi_late',
            $this->intent('pi_late', 'requires_action'),
            $this->intent('pi_late', 'succeeded'));
        $this->http->on('post', 'payment_intents/pi_late/cancel', [
            '_status' => 400,
            'error' => ['type' => 'invalid_request_error', 'code' => 'payment_intent_unexpected_state', 'message' => 'This PaymentIntent could not be canceled because it has a status of succeeded.'],
        ]);

        $this->artisan('payments:settle-card-authentications')->assertSuccessful();

        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    #[Test]
    public function one_payment_failing_to_settle_does_not_stop_the_others(): void
    {
        $broken = $this->awaiting($this->invoice(), 'pi_broken', 5);
        $this->http->on('get', 'payment_intents/pi_broken', [
            '_status' => 500,
            'error' => ['type' => 'api_error', 'message' => 'Stripe is having a moment.'],
        ]);
        $invoice = $this->invoice();
        $fine = $this->awaiting($invoice, 'pi_fine', 5);
        $this->http->on('get', 'payment_intents/pi_fine', $this->intent('pi_fine', 'succeeded'));

        $this->artisan('payments:settle-card-authentications')->assertSuccessful();

        $this->assertSame(Payment::STATUS_PENDING, $broken->fresh()->status, 'left for the next pass');
        $this->assertSame(Payment::STATUS_SUCCEEDED, $fine->fresh()->status);
    }

    #[Test]
    public function only_payments_awaiting_authentication_are_read(): void
    {
        $invoice = $this->invoice();
        Payment::create([
            'invoice_id' => $invoice->id,
            'transaction_id' => 'CASH-1',
            'amount' => 150,
            'status' => Payment::STATUS_PENDING,
            'is_environment' => 0,
        ]);

        $this->artisan('payments:settle-card-authentications')->assertSuccessful();

        $this->assertFalse($this->http->called('payment_intents'));
    }

    #[Test]
    public function it_runs_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'payments:settle-card-authentications'));

        $this->assertNotNull($event, 'the command is scheduled');
        $this->assertSame('*/5 * * * *', $event->expression);
    }

    #[Test]
    public function the_auto_charge_cron_leaves_an_invoice_mid_challenge_alone(): void
    {
        $invoice = $this->invoice();
        $this->awaiting($invoice, 'pi_mid', 2);
        Borrower::find($invoice->entity_id)->update([
            'default_payment_method' => PaymentProvider::STRIPE,
            'stripe_customer_id' => ['customer_id' => 'cus_saved', 'payment_mode' => PaymentProvider::MODE_SANDBOX],
        ]);

        app()->call([new ChargeSubscriptionInvoiceJob($invoice), 'handle']);

        $this->assertCount(0, $this->http->requestsTo('post', 'payment_intents'), 'no off-session charge while the payer authenticates');
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }
}
