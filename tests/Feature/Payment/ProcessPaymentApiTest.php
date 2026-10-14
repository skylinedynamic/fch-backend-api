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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStripeHttpClient;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The payment endpoints: POST /borrower/process-payment, /process-payment,
 * /process-payment-for-users, /payment/process (all one action) and
 * GET /payment/providers. The charge itself is covered at service level in
 * tests/Integration/Payment; these pin who may pay what over HTTP.
 */
#[CoversClass(UnifiedPaymentController::class)]
class ProcessPaymentApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $borrowerUser;
    private PaymentProvider $cash;
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

        $this->cash = PaymentProvider::create(['name' => PaymentProvider::CASH, 'display_name' => 'Cash', 'status' => true]);
        $this->stripe = PaymentProvider::create(['name' => PaymentProvider::STRIPE, 'display_name' => 'Stripe', 'status' => true]);

        $config = app(PaymentMethodConfigurationService::class);
        $config->updateOrCreateConfig($this->partner->id, [
            'payment_provider_id' => $this->cash->id,
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'status' => true,
            'is_default' => true,
            'configuration' => [],
        ]);
        $config->updateOrCreateConfig($this->partner->id, [
            'payment_provider_id' => $this->stripe->id,
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'status' => true,
            'is_default' => false,
            'configuration' => [
                'sandbox' => ['publishable_key' => 'pk_test_x', 'secret_key' => 'sk_test_x'],
                'production' => ['publishable_key' => 'pk_live_x', 'secret_key' => 'sk_live_x'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    private function invoiceFor(User $borrowerUser, array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $borrowerUser->partner_id,
            'borrower_id' => $borrowerUser->entity_id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrowerUser->entity_id,
            'customer_name' => $borrowerUser->name,
            'email' => $borrowerUser->email,
            'payment_amount' => 800.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
        ], $attributes));
    }

    private function payCash(Invoice $invoice, string $url = '/api/v1/borrower/process-payment')
    {
        return $this->postJson($url, [
            'invoice_id' => $invoice->id,
            'payment_method_id' => $this->cash->id,
            'payment_token' => json_encode(['mode' => 'manual', 'transaction_number' => 'CASH-001']),
        ]);
    }

    public static function paymentUrls(): array
    {
        return [
            'borrower portal' => ['/api/v1/borrower/process-payment'],
            'process-payment' => ['/api/v1/process-payment'],
            'process-payment-for-users' => ['/api/v1/process-payment-for-users'],
            'payment/process' => ['/api/v1/payment/process'],
        ];
    }

    #[Test]
    #[DataProvider('paymentUrls')]
    public function every_payment_url_needs_a_signed_in_user(string $url): void
    {
        $this->postJson($url, [])->assertUnauthorized();
    }

    #[Test]
    #[DataProvider('paymentUrls')]
    public function a_manual_payment_puts_the_borrowers_invoice_into_processing(string $url): void
    {
        $this->actingAsUser($this->borrowerUser);
        $invoice = $this->invoiceFor($this->borrowerUser);

        $this->payCash($invoice, $url)
            ->assertOk()
            ->assertJsonPath('data.success', true)
            ->assertJsonPath('data.transaction_id', 'CASH-001');

        $this->assertSame(Invoice::STATUS_PROCESSING, $invoice->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'status' => Payment::STATUS_PROCESSING,
            'is_manual' => true,
            'transaction_id' => 'CASH-001',
        ]);
    }

    #[Test]
    public function a_manual_payment_does_not_activate_the_borrower_until_it_is_approved(): void
    {
        $this->actingAsUser($this->borrowerUser);

        $this->payCash($this->invoiceFor($this->borrowerUser))->assertOk();

        $this->assertSame(Borrower::STATUS_ONBOARDING, Borrower::find($this->borrowerUser->entity_id)->status);
    }

    #[Test]
    public function a_card_payment_marks_the_invoice_paid_and_activates_the_borrower(): void
    {
        $this->http->willReturn('payment_intents', [
            'id' => 'pi_http_1',
            'object' => 'payment_intent',
            'status' => 'succeeded',
        ]);
        $this->actingAsUser($this->borrowerUser);
        $invoice = $this->invoiceFor($this->borrowerUser);

        $this->postJson('/api/v1/borrower/process-payment', [
            'invoice_id' => $invoice->id,
            'payment_method_id' => $this->stripe->id,
            'payment_token' => 'pm_card_visa',
        ])->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(Borrower::STATUS_ACTIVE, Borrower::find($this->borrowerUser->entity_id)->status);
        $this->assertContains('sk_live_x', $this->http->apiKeysUsed());
    }

    #[Test]
    public function a_borrower_cannot_pay_someone_elses_invoice(): void
    {
        $other = $this->makeBorrowerUser($this->partner);
        $invoice = $this->invoiceFor($other);
        $this->actingAsUser($this->borrowerUser);

        $this->payCash($invoice)
            ->assertStatus(400)
            ->assertJsonPath('message', __('payment.unauthorized_payment_attempt'));

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    #[Test]
    public function a_partner_admin_cannot_pay_a_borrowers_invoice_through_the_generic_route(): void
    {
        $invoice = $this->invoiceFor($this->borrowerUser);
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->payCash($invoice, '/api/v1/process-payment')->assertStatus(400);

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    #[Test]
    public function only_an_unpaid_invoice_can_be_paid(): void
    {
        $this->actingAsUser($this->borrowerUser);
        $invoice = $this->invoiceFor($this->borrowerUser, ['status' => Invoice::STATUS_PAID]);

        $this->payCash($invoice)
            ->assertStatus(400)
            ->assertJsonPath('message', __('payment.only_unpaid_can_be_paid'));

        $this->assertDatabaseCount('payments', 0);
    }

    #[Test]
    public function the_borrower_route_refuses_a_partner_account(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->payCash($this->invoiceFor($this->borrowerUser))->assertForbidden();
    }

    #[Test]
    public function the_invoice_method_and_token_are_required_and_must_exist(): void
    {
        $this->actingAsUser($this->borrowerUser);

        $this->postJson('/api/v1/borrower/process-payment', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice_id', 'payment_method_id', 'payment_token']);

        $this->postJson('/api/v1/borrower/process-payment', [
            'invoice_id' => 999999,
            'payment_method_id' => 999999,
            'payment_token' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors(['invoice_id', 'payment_method_id']);
    }

    #[Test]
    public function providers_lists_the_active_gateways_of_the_callers_partner(): void
    {
        $this->actingAsUser($this->borrowerUser);

        $names = collect($this->getJson('/api/v1/payment/providers')->assertOk()->json('data'))->pluck('name');

        $this->assertEqualsCanonicalizing([PaymentProvider::CASH, PaymentProvider::STRIPE], $names->all());
    }

    #[Test]
    public function providers_leaves_out_a_gateway_the_partner_switched_off(): void
    {
        app(PaymentMethodConfigurationService::class)->updateOrCreateConfig($this->partner->id, [
            'payment_provider_id' => $this->cash->id,
            'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'status' => false,
            'configuration' => [],
        ]);
        $this->actingAsUser($this->borrowerUser);

        $names = collect($this->getJson('/api/v1/payment/providers')->assertOk()->json('data'))->pluck('name');

        $this->assertSame([PaymentProvider::STRIPE], $names->all());
    }

    #[Test]
    public function providers_needs_a_signed_in_user(): void
    {
        $this->getJson('/api/v1/payment/providers')->assertUnauthorized();
    }
}
