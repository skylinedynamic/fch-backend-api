<?php

namespace Tests\Feature\Invoice;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\PaymentMethod\PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-137: a partner's Borrower Fee invoice PDF was addressed to the borrower
 * and called an enrollment, while the invoice window bills the partner for a
 * Borrower Fee. The PDF read invoices.borrower first; the window reads whoever
 * pays (entity_type/entity_id, via InvoicePartyResolver). The PDF now uses the
 * same payer and names the invoice by its type, as the window does.
 */
class InvoicePdfBilledToTest extends TestCase
{
    use RefreshDatabase;

    private const BUSINESS_INFO = [
        'business_logo_path' => 'https://example.test/logo.png',
        'company_name' => 'Four Corner Funding',
        'address_line_1' => '1 Main St',
        'city' => 'Austin',
        'state' => 'TX',
        'postal_code' => '78701',
        'phone_number' => '555-0100',
        'email' => 'billing@example.test',
    ];

    private Partner $partner;
    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'full_name' => 'Hal Host',
            'company_name' => 'Host LLC',
            'email' => 'host@example.test',
        ]);

        $this->partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'full_name' => 'Wanda Romanoff',
            'company_name' => 'Dark Magic',
            'email' => 'wanda@example.test',
            'phone_number' => '555-0200',
            'address_line_1' => '2 Hex Rd',
            'city' => 'Kenner',
            'state' => 'Louisiana',
            'postal_code' => '56456',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ONBOARDING,
            'first_name' => 'QA-TEST Retest033',
            'last_name' => 'Paid',
            'email' => 'retest033@example.test',
            'phone_number' => '555-0300',
            'business_address' => '9 Market St',
        ]);
    }

    private function render(array $attributes): string
    {
        $invoice = Invoice::create(array_merge([
            'invoice_id' => 'INV-20261028001',
            'partner_id' => $this->partner->id,
            'borrower_id' => $this->borrower->id,
            'payment_amount' => 800,
            'status' => Invoice::STATUS_PAID,
            'currency' => 'USD',
            'details' => ['payment_method' => 'Stripe', 'card_last4' => '4242'],
        ], $attributes))->fresh(['partner', 'borrower', 'subscription']);

        $html = view('invoices.download', ['invoice' => $invoice, 'business_info' => self::BUSINESS_INFO])->render();

        return preg_replace('/\s+/', ' ', strip_tags($html));
    }

    private function borrowerFee(): string
    {
        return $this->render([
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
        ]);
    }

    #[Test]
    public function a_borrower_fee_pdf_is_addressed_to_the_partner_who_pays_it(): void
    {
        $text = $this->borrowerFee();

        $billing = $this->between($text, 'BILLING ADDRESS', 'Invoice No');
        $this->assertStringContainsString('Wanda Romanoff', $billing);
        $this->assertStringContainsString('2 Hex Rd', $billing);
        $this->assertStringContainsString('wanda@example.test', $billing);
        $this->assertStringNotContainsString('QA-TEST Retest033', $billing);
        $this->assertStringNotContainsString('retest033@example.test', $billing);
    }

    #[Test]
    public function the_card_holder_is_the_partner_who_paid(): void
    {
        $text = $this->borrowerFee();

        $this->assertStringContainsString('Card Holder: Wanda Romanoff', $text);
        $this->assertStringNotContainsString('Card Holder: QA-TEST Retest033', $text);
    }

    /**
     * Defect (BUG-194): the fee is named "Client Activation Fee" (Plan & Fee);
     * the PDF still described it as "Borrower Fee".
     */
    #[Test]
    public function a_borrower_fee_is_named_a_client_activation_fee_not_an_enrollment(): void
    {
        $text = $this->borrowerFee();

        $this->assertStringNotContainsString('Enrollment', $text);
        $this->assertStringContainsString('Description', $text);
        $this->assertStringContainsString('Client Activation Fee $800.00', $text);
        $this->assertStringNotContainsString('Borrower Fee', $text);
    }

    /** A client's own invoice is still addressed to the client. */
    #[Test]
    public function an_invoice_the_borrower_pays_is_still_addressed_to_the_borrower(): void
    {
        $text = $this->render([
            'type' => Invoice::INVOICE_TYPE_DOWN_PAYMENT,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
        ]);

        $this->assertStringContainsString('QA-TEST Retest033 Paid', $this->between($text, 'BILLING ADDRESS', 'Invoice No'));
        $this->assertStringContainsString('Card Holder: QA-TEST Retest033 Paid', $text);
        $this->assertStringContainsString('Down Payment', $text);
    }

    /** A commission payout carries the client too, but its details are the partner paid. */
    #[Test]
    public function a_commission_payout_pdf_shows_the_partner_paid_not_the_client(): void
    {
        $text = $this->render([
            'type' => Invoice::INVOICE_TYPE_COMMISSION_PAYOUT,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
        ]);

        $details = $this->between($text, 'PARTNER DETAILS', 'Invoice No');
        $this->assertStringContainsString('Wanda Romanoff', $details);
        $this->assertStringNotContainsString('QA-TEST Retest033', $details);
        $this->assertStringContainsString('Payee Name: Wanda Romanoff', $text);
    }

    /**
     * Defect (BUG-206): a Client Activation Fee paid by Bank Transfer printed
     * "Payment Method: Stripe", a Card Holder and "To be paid by Stripe".
     */
    #[Test]
    public function an_invoice_paid_by_bank_transfer_prints_the_transfer_not_a_card(): void
    {
        $invoice = Invoice::create([
            'invoice_id' => 'INV-20261105007',
            'partner_id' => $this->partner->id,
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
            'payment_amount' => 800,
            'status' => Invoice::STATUS_PAID,
            'currency' => 'USD',
        ]);
        Payment::create([
            'partner_id' => $this->partner->id,
            'invoice_id' => $invoice->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
            'transaction_id' => 'QA-TEST-REF-0322',
            'amount' => 800,
            'currency' => 'USD',
            'payment_from' => PaymentProvider::BANK_TRANSFER,
            'payment_type' => PaymentProvider::BANK_TRANSFER,
            'is_manual' => true,
            'status' => Payment::STATUS_SUCCEEDED,
            'transaction_date' => now(),
            'is_environment' => false,
            'details' => ['transaction_number' => 'QA-TEST-REF-0322'],
        ]);

        $html = view('invoices.download', ['invoice' => $invoice->fresh(['partner', 'borrower', 'subscription']), 'business_info' => self::BUSINESS_INFO])->render();
        $text = preg_replace('/\s+/', ' ', strip_tags($html));

        $this->assertStringContainsString('Payment Method: Bank Transfer', $text);
        $this->assertStringContainsString('Reference: QA-TEST-REF-0322', $text);
        $this->assertStringContainsString('Paid By: Wanda Romanoff', $text);
        $this->assertStringNotContainsString('Card Holder', $text);
        $this->assertStringNotContainsString('Stripe', $text);
    }

    private function between(string $text, string $from, string $to): string
    {
        $start = strpos($text, $from);
        $this->assertNotFalse($start, "'{$from}' is not in the PDF");
        $end = strpos($text, $to, $start);

        return substr($text, $start, ($end === false ? strlen($text) : $end) - $start);
    }
}
