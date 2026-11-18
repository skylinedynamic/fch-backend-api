<?php

namespace Tests\Feature\Payment;

use App\Enums\Permission;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Services\FCH\Common\Setting\PaymentMethodConfigurationService;
use App\Services\GlobalPayment\UnifiedPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeStripeHttpClient;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-202: a partner's staff saw Pay Now on the partner's own invoices, but the
 * API refused every attempt (400 "Unauthorized invoice payment attempt.").
 *
 * The partner's own staff may now pay the partner's own invoices; "Pay
 * Invoice" decides only whether the panel offers Pay Now. Who paid, approved or rejected is kept: `paid_by` on the payment - succeeded
 * or failed - and on the invoice once it is paid; `approved_by` on both when a
 * manual payment is approved; `rejected_by` on the payment only.
 */
class PayInvoiceTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private User $hostAdmin;
    private PaymentProvider $cash;
    private PaymentProvider $stripe;
    private FakeStripeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->http = FakeStripeHttpClient::install();

        $this->host = $this->makeHostPartner();
        // The host's admins approve manual payments; the API checks the permission (R3-BUG-037).
        $this->hostAdmin = $this->makePartnerAdmin($this->host, [Permission::ApprovePayment->value]);
        $this->partner = $this->makeCertifiedPartner();

        // A partner's invoice is paid to the host, through the host's gateways.
        $this->cash = PaymentProvider::create(['name' => PaymentProvider::CASH, 'display_name' => 'Cash', 'status' => true]);
        $this->stripe = PaymentProvider::create(['name' => PaymentProvider::STRIPE, 'display_name' => 'Stripe', 'status' => true]);
        $config = app(PaymentMethodConfigurationService::class);
        $config->updateOrCreateConfig($this->host->id, [
            'payment_provider_id' => $this->cash->id, 'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'status' => true, 'is_default' => true, 'configuration' => [],
        ]);
        $config->updateOrCreateConfig($this->host->id, [
            'payment_provider_id' => $this->stripe->id, 'payment_mode' => PaymentProvider::MODE_PRODUCTION,
            'status' => true, 'is_default' => false,
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

    private function partnerInvoice(?Partner $partner = null): Invoice
    {
        $partner ??= $this->partner;

        return Invoice::create([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'customer_name' => $partner->full_name,
            'email' => $partner->email,
            'payment_amount' => 800.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
            'is_partner_paying' => true,
        ]);
    }

    private function payCash(Invoice $invoice)
    {
        return $this->postJson('/api/v1/payment/process', [
            'invoice_id' => $invoice->id,
            'payment_method_id' => $this->cash->id,
            'payment_token' => json_encode(['mode' => 'manual', 'transaction_number' => 'CASH-202']),
        ]);
    }

    private function payCard(Invoice $invoice)
    {
        return $this->postJson('/api/v1/payment/process', [
            'invoice_id' => $invoice->id,
            'payment_method_id' => $this->stripe->id,
            'payment_token' => 'pm_card_visa',
        ]);
    }

    private function staffWithPayInvoice(): User
    {
        return $this->makeStaffUser($this->partner, [Permission::PayInvoice->value]);
    }

    // ── Who may pay ─────────────────────────────────────────────────

    #[Test]
    public function staff_with_pay_invoice_pays_the_partners_own_invoice(): void
    {
        $staff = $this->actingAsUser($this->staffWithPayInvoice());
        $invoice = $this->partnerInvoice();

        $this->payCash($invoice)->assertOk();

        $this->assertSame(Invoice::STATUS_PROCESSING, $invoice->fresh()->status);
        $this->assertSame($staff->id, Payment::where('invoice_id', $invoice->id)->sole()->paid_by);
    }

    // "Pay Invoice" is checked by the panel only (it hides Pay Now, 07-Nov
    // decision); the API lets any of the partner's own staff pay.
    #[Test]
    public function the_api_lets_the_partners_staff_pay_without_checking_the_permission(): void
    {
        $staff = $this->actingAsUser($this->makeStaffUser($this->partner, ['view invoices']));
        $invoice = $this->partnerInvoice();

        $this->payCash($invoice)->assertOk();

        $this->assertSame($staff->id, Payment::where('invoice_id', $invoice->id)->sole()->paid_by);
    }

    #[Test]
    public function a_borrower_of_the_partner_still_cannot_pay_the_partners_invoice(): void
    {
        // A borrower's login carries its partner's partner_id.
        $this->actingAsUser($this->makeBorrowerUser($this->partner));
        $invoice = $this->partnerInvoice();

        $this->payCash($invoice)
            ->assertStatus(400)
            ->assertJsonPath('message', __('payment.unauthorized_payment_attempt'));

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    #[Test]
    public function pay_invoice_never_reaches_another_partners_invoice(): void
    {
        $this->actingAsUser($this->staffWithPayInvoice());
        $invoice = $this->partnerInvoice($this->makeWhiteLabelPartner());

        $this->payCash($invoice)->assertStatus(400);

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    #[Test]
    public function the_partner_owner_still_pays_their_own_invoice(): void
    {
        $owner = $this->actingAsUser($this->makePartnerAdmin($this->partner));

        $this->payCash($invoice = $this->partnerInvoice())->assertOk();

        $this->assertSame($owner->id, Payment::where('invoice_id', $invoice->id)->sole()->paid_by);
    }

    // ── paid_by ─────────────────────────────────────────────────────

    #[Test]
    public function a_card_payment_records_who_paid_on_the_payment_and_the_invoice(): void
    {
        $this->http->willReturn('payment_intents', ['id' => 'pi_202', 'object' => 'payment_intent', 'status' => 'succeeded']);
        $staff = $this->actingAsUser($this->staffWithPayInvoice());
        $invoice = $this->partnerInvoice();

        $this->payCard($invoice)->assertOk();

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame($staff->id, $invoice->paid_by);
        $this->assertSame($staff->id, Payment::where('invoice_id', $invoice->id)->sole()->paid_by);
    }

    #[Test]
    public function a_declined_card_records_who_tried_on_the_failed_payment_only(): void
    {
        $this->http->willFail('payment_intents', 402, ['type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card was declined.']);
        $staff = $this->actingAsUser($this->staffWithPayInvoice());
        $invoice = $this->partnerInvoice();

        $this->payCard($invoice);

        $failed = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame(Payment::STATUS_FAILED, $failed->status);
        $this->assertSame($staff->id, $failed->paid_by);
        $this->assertNull($invoice->fresh()->paid_by);
    }

    #[Test]
    public function an_automatic_charge_has_no_payer_user_even_while_someone_is_signed_in(): void
    {
        // The borrower-fee auto-charge runs inside another user's request
        // (approving a borrower's payment); nobody chose to pay it.
        $this->actingAsUser($this->hostAdmin);
        $invoice = $this->partnerInvoice();

        app(UnifiedPaymentService::class)->triggerPartnerAutoChargeForBorrowerInvoice($invoice);

        $attempt = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame(Payment::STATUS_FAILED, $attempt->status);
        $this->assertNull($attempt->paid_by);
    }

    // ── approved_by / rejected_by ───────────────────────────────────

    #[Test]
    public function approving_a_manual_payment_records_the_payer_and_the_approver(): void
    {
        $staff = $this->actingAsUser($this->staffWithPayInvoice());
        $this->payCash($invoice = $this->partnerInvoice())->assertOk();
        $payment = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertNull($invoice->fresh()->paid_by, 'not paid yet - only processing');

        $this->actingAsUser($this->hostAdmin);
        $this->postJson("/api/v1/common/payments/{$payment->id}/approve", ['note' => 'Cash received'])->assertOk();

        $payment->refresh();
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame($staff->id, $invoice->paid_by, 'who paid, not who approved');
        $this->assertSame($this->hostAdmin->id, $invoice->approved_by);
        $this->assertSame($staff->id, $payment->paid_by);
        $this->assertSame($this->hostAdmin->id, $payment->approved_by);
        $this->assertNull($payment->rejected_by);
    }

    #[Test]
    public function rejecting_a_manual_payment_records_the_rejecter_on_the_payment_only(): void
    {
        $staff = $this->actingAsUser($this->staffWithPayInvoice());
        $this->payCash($invoice = $this->partnerInvoice())->assertOk();
        $payment = Payment::where('invoice_id', $invoice->id)->sole();

        $this->actingAsUser($this->hostAdmin);
        $this->postJson("/api/v1/common/payments/{$payment->id}/reject", ['reason' => 'No cash received'])->assertOk();

        $payment->refresh();
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertSame($this->hostAdmin->id, $payment->rejected_by);
        $this->assertSame($staff->id, $payment->paid_by);
        $this->assertNull($payment->approved_by);
        $this->assertNull($invoice->paid_by);
        $this->assertNull($invoice->approved_by);
    }
}
