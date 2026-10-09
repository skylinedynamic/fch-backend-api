<?php

namespace Tests\Feature\Billing;

use App\Mail\Invoice\InvoiceMail;
use App\Models\Invoice\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The invoice email is queued with its PDF attached.
 *
 * A queue payload is JSON, and a PDF is binary: carried raw, json_encode()
 * refused the job, the push threw, InvoiceService logged the exception and no
 * invoice email was ever sent. The PDF is therefore carried base64-encoded.
 */
#[CoversClass(InvoiceMail::class)]
class InvoiceMailQueueTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_pdf_survives_the_trip_through_the_queue(): void
    {
        $pdf = "%PDF-1.4\n\xe2\xe3\xcf\xd3 binary \x00\xff";
        // Saved: SerializesModels fetches it again when the job is unserialized.
        $invoice = Invoice::create([
            'invoice_id' => 'INV-9',
            'payment_amount' => 10,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
        ]);

        $payload = json_encode(['data' => serialize(new InvoiceMail($invoice, $pdf, 1))]);
        $this->assertNotFalse($payload, json_last_error_msg());

        $attachments = unserialize(json_decode($payload, true)['data'])->attachments();

        $this->assertCount(1, $attachments);
        $this->assertSame('Invoice-INV-9.pdf', $attachments[0]->as);
        $this->assertSame($pdf, $attachments[0]->attachWith(
            fn () => $this->fail('the PDF is attached from data, not a path'),
            fn (\Closure $data) => $data(),
        ));
    }

    #[Test]
    public function an_invoice_without_a_pdf_has_no_attachment(): void
    {
        $this->assertSame([], (new InvoiceMail(new Invoice(), null, 1))->attachments());
    }
}
