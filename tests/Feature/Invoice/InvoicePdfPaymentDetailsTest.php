<?php

namespace Tests\Feature\Invoice;

use App\Models\Invoice\Invoice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-093: the invoice PDF's "Payment Details" read as a Stripe card payment
 * on an invoice nothing had been paid on - Payment Method fell back to
 * "Stripe", the customer was printed as the Card Holder and the invoice total
 * as the amount paid. Until it is paid it now says so and shows the amount due.
 */
class InvoicePdfPaymentDetailsTest extends TestCase
{
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

    private function render(array $attributes): string
    {
        $invoice = new Invoice(array_merge([
            'invoice_id' => 'INV-20261020009',
            'type' => Invoice::INVOICE_TYPE_DOWN_PAYMENT,
            'customer_name' => 'QA-TEST WL Install Owner',
            'email' => 'owner@example.test',
            'payment_amount' => 1000,
            'details' => [],
        ], $attributes));
        $invoice->created_at = now();

        // Strip tags so the assertions read the text the PDF prints.
        $html = view('invoices.download', ['invoice' => $invoice, 'business_info' => self::BUSINESS_INFO])->render();

        return preg_replace('/\s+/', ' ', strip_tags($html));
    }

    public static function unpaidStatuses(): array
    {
        return [
            'unpaid' => [Invoice::STATUS_UNPAID],
            'overdue' => [Invoice::STATUS_OVERDUE],
            'failed' => [Invoice::STATUS_FAILED],
        ];
    }

    #[Test]
    #[DataProvider('unpaidStatuses')]
    public function an_invoice_not_yet_paid_does_not_read_as_a_card_payment(string $status): void
    {
        $text = $this->render(['status' => $status]);

        $this->assertStringContainsString('Payment Method: Not paid yet', $text);
        $this->assertStringContainsString('Amount Due: $1,000.00', $text);
        $this->assertStringNotContainsString('Card Holder', $text);
        $this->assertStringNotContainsString('Stripe', $this->paymentDetails($text));
        $this->assertStringNotContainsString('Total Amount:', $this->paymentDetails($text));
    }

    /** Processing is a payment made and awaiting approval, so it is not "not paid". */
    #[Test]
    public function a_processing_payment_keeps_its_payment_details(): void
    {
        $text = $this->render(['status' => Invoice::STATUS_PROCESSING, 'details' => ['payment_method' => 'Bank Transfer']]);

        $this->assertStringContainsString('Payment Method: Bank Transfer', $text);
        $this->assertStringNotContainsString('Not paid yet', $text);
    }

    #[Test]
    public function a_paid_invoice_keeps_its_payment_details(): void
    {
        $text = $this->render([
            'status' => Invoice::STATUS_PAID,
            'details' => ['payment_method' => 'Stripe', 'card_last4' => '4242'],
        ]);

        $this->assertStringContainsString('Payment Method: Stripe', $text);
        $this->assertStringContainsString('Card Holder: QA-TEST WL Install Owner', $text);
        $this->assertStringContainsString('xxxx xxxx xxxx 4242', $text);
        $this->assertStringContainsString('Total Amount: $1,000.00', $text);
        $this->assertStringNotContainsString('Not paid yet', $text);
    }

    /** The "Payment Details" block alone, so the notes below it do not count. */
    private function paymentDetails(string $text): string
    {
        $start = strpos($text, 'Payment Details:');
        $end = strpos($text, 'NOTES:');

        return substr($text, $start, $end - $start);
    }
}
