<?php

namespace Tests\Integration\Invoice;

use App\Http\Resources\Borrower\Invoice\InvoiceDetailResource as BorrowerInvoiceDetailResource;
use App\Http\Resources\FCH\Invoice\InvoiceDetailResource as FchInvoiceDetailResource;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Partner\SubscriptionPlan;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\Subscription\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The invoice detail payload sent to the preview modal and the invoice page.
 *
 * It carries the invoice's OWN figures only. A borrower fee is linked to the
 * borrower's subscription for traceability, so reading the plan off that
 * subscription made a $800 fee render as the borrower's $2,497 enrollment.
 */
#[CoversClass(BorrowerInvoiceDetailResource::class)]
#[CoversClass(FchInvoiceDetailResource::class)]
class InvoiceDetailResourceTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_KEYS = [
        'id', 'invoice_id', 'entity_type', 'type', 'status', 'payment_amount',
        'payment_date', 'created_at', 'branding', 'billed_from', 'billed_to',
        // Both portals warn before a payment that reactivates a cancelled subscription (BUG-069).
        'reactivates_subscription',
        // How it was actually paid, so the window stops saying "Stripe" for every invoice (BUG-206).
        'payment_method', 'payment_reference', 'is_card_payment',
    ];

    private Partner $reseller;
    private Borrower $borrower;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'Host LLC',
            'email' => 'host@example.test',
        ]);

        $this->reseller = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Reseller LLC',
            'email' => 'reseller@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $this->reseller->id,
            'status' => Borrower::STATUS_ONBOARDING,
            'first_name' => 'Bo',
            'last_name' => 'Rower',
            'email' => 'bo@example.test',
        ]);

        $this->subscription = Subscription::create([
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            'enrollment_type' => SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT,
            'installment_type' => 0,
            'installment_count' => 1,
            'installments_paid' => 0,
            'payable_amount' => 2497.00,
            'monthly_platform_fee' => 49.00,
            'current_phase' => Subscription::PHASE_ENROLLMENT,
            'status' => Subscription::STATUS_PENDING,
        ]);
    }

    private function borrowerFee(): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-FEE',
            'subscription_id' => $this->subscription->id,
            'partner_id' => $this->reseller->id,
            'borrower_id' => $this->borrower->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->reseller->id,
            'customer_name' => 'Reseller LLC',
            'email' => 'reseller@example.test',
            'payment_amount' => 800.00,
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
            'is_partner_paying' => true,
            'currency' => 'USD',
        ])->fresh(['partner', 'borrower', 'subscription']);
    }

    private function enrollment(): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-ENR',
            'subscription_id' => $this->subscription->id,
            'partner_id' => $this->reseller->id,
            'borrower_id' => $this->borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            'payment_amount' => 2497.00,
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
            'currency' => 'USD',
        ])->fresh(['partner', 'borrower', 'subscription']);
    }

    /** The payload as it goes over the wire, conditional fields resolved. */
    private function toArray(string $resource, Invoice $invoice): array
    {
        return (new $resource($invoice))->resolve(Request::create('/'));
    }

    private function assertKeys(array $expected, array $data): void
    {
        $actual = array_keys($data);
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function the_admin_payload_sends_only_what_the_invoice_screens_read(): void
    {
        $data = $this->toArray(FchInvoiceDetailResource::class, $this->enrollment());

        $this->assertKeys(
            [...self::SHARED_KEYS, 'displayable_payment_amount', 'enrollment_type', 'note'],
            $data
        );
        $this->assertKeys(['site_name', 'logo_document_full_url', 'logo_sidebar_full_url'], $data['branding']);
    }

    #[Test]
    public function the_borrower_payload_sends_only_what_the_invoice_screens_read(): void
    {
        $data = $this->toArray(BorrowerInvoiceDetailResource::class, $this->enrollment());

        $this->assertKeys([...self::SHARED_KEYS, 'displayable_amount', 'enrollment_type'], $data);
        $this->assertKeys(['site_name', 'logo_document_full_url', 'logo_sidebar_full_url'], $data['branding']);
    }

    /**
     * Defect (BUG-194): the fee is named "Client Activation Fee" (Plan & Fee);
     * the invoice still called it "Borrower Fee".
     */
    #[Test]
    public function a_borrower_fee_carries_its_own_amount_and_none_of_the_borrowers_plan(): void
    {
        $data = $this->toArray(FchInvoiceDetailResource::class, $this->borrowerFee());

        $this->assertSame(800.0, $data['payment_amount']);
        $this->assertSame('Client Activation Fee', $data['type']);
        $this->assertArrayNotHasKey('enrollment_type', $data);
        $this->assertArrayNotHasKey('payable_amount_sub', $data);
        $this->assertArrayNotHasKey('monthly_platform_fee', $data);
    }

    #[Test]
    public function an_enrollment_invoice_names_the_plan_it_bills_for(): void
    {
        foreach ([FchInvoiceDetailResource::class, BorrowerInvoiceDetailResource::class] as $resource) {
            $data = $this->toArray($resource, $this->enrollment());

            $this->assertSame(SubscriptionPlan::ENROLLMENT_TYPE_ONE_TIME_PAYMENT, $data['enrollment_type'], $resource);
            $this->assertSame(2497.0, $data['payment_amount'], $resource);
        }
    }

    #[Test]
    public function a_borrower_fee_is_billed_to_the_paying_partner(): void
    {
        $data = $this->toArray(FchInvoiceDetailResource::class, $this->borrowerFee());

        $this->assertSame('Host LLC', $data['billed_from']['company_name']);
        $this->assertSame('Reseller LLC', $data['billed_to']['company_name']);
    }
    /** A payment recorded against $invoice, as the checkout records it. */
    private function pay(Invoice $invoice, string $status, string $from, ?string $type, array $details = [], bool $manual = true): Payment
    {
        return Payment::create([
            'partner_id' => $this->reseller->id,
            'invoice_id' => $invoice->id,
            'entity_type' => $invoice->entity_type,
            'entity_id' => $invoice->entity_id,
            'transaction_id' => 'TX-' . uniqid(),
            'amount' => $invoice->payment_amount,
            'currency' => 'USD',
            'payment_from' => $from,
            'payment_type' => $type,
            'is_manual' => $manual,
            'status' => $status,
            'transaction_date' => now(),
            'is_environment' => false,
            'details' => $details,
        ]);
    }

    /**
     * Defect (BUG-206): an invoice paid by Bank Transfer showed "Payment Method:
     * Stripe" and a Card Holder. The payload now says how it was really paid.
     */
    #[Test]
    public function an_invoice_paid_by_bank_transfer_says_bank_transfer_and_its_reference(): void
    {
        $invoice = $this->borrowerFee();
        $invoice->update(['status' => Invoice::STATUS_PAID]);
        $this->pay($invoice, Payment::STATUS_SUCCEEDED, PaymentProvider::BANK_TRANSFER, PaymentProvider::BANK_TRANSFER, ['transaction_number' => 'QA-TEST-REF-0322']);

        foreach ([FchInvoiceDetailResource::class, BorrowerInvoiceDetailResource::class] as $resource) {
            $data = $this->toArray($resource, $invoice->fresh());

            $this->assertSame(PaymentProvider::BANK_TRANSFER, $data['payment_method'], $resource);
            $this->assertSame('QA-TEST-REF-0322', $data['payment_reference'], $resource);
            $this->assertFalse($data['is_card_payment'], $resource);
        }
    }

    #[Test]
    public function an_invoice_paid_by_card_says_the_card_gateway(): void
    {
        $invoice = $this->enrollment();
        $invoice->update(['status' => Invoice::STATUS_PAID]);
        $this->pay($invoice, Payment::STATUS_SUCCEEDED, PaymentProvider::STRIPE, PaymentProvider::TYPE_CARD, [], false);

        $data = $this->toArray(FchInvoiceDetailResource::class, $invoice->fresh());

        $this->assertSame(PaymentProvider::STRIPE, $data['payment_method']);
        $this->assertNull($data['payment_reference']);
        $this->assertTrue($data['is_card_payment']);
    }

    /** A cash payment awaiting approval is how the invoice is being paid. */
    #[Test]
    public function a_payment_awaiting_approval_names_its_method(): void
    {
        $invoice = $this->borrowerFee();
        $invoice->update(['status' => Invoice::STATUS_PROCESSING]);
        $this->pay($invoice, Payment::STATUS_PROCESSING, PaymentProvider::CASH, PaymentProvider::CASH, ['transaction_number' => 'CASH-1']);

        $data = $this->toArray(FchInvoiceDetailResource::class, $invoice->fresh());

        $this->assertSame(PaymentProvider::CASH, $data['payment_method']);
        $this->assertSame('CASH-1', $data['payment_reference']);
    }

    /** Nothing paid, or only a refused attempt: no method at all, never "Stripe". */
    #[Test]
    public function an_unpaid_invoice_has_no_payment_method_even_after_a_rejected_attempt(): void
    {
        $invoice = $this->borrowerFee();
        $this->assertNull($this->toArray(FchInvoiceDetailResource::class, $invoice)['payment_method']);

        $this->pay($invoice, Payment::STATUS_REJECTED, PaymentProvider::BANK_TRANSFER, PaymentProvider::BANK_TRANSFER, ['transaction_number' => 'BAD']);
        $data = $this->toArray(FchInvoiceDetailResource::class, $invoice->fresh());

        $this->assertNull($data['payment_method']);
        $this->assertNull($data['payment_reference']);
        $this->assertFalse($data['is_card_payment']);
    }
}
