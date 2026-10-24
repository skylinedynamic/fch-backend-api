<?php

namespace Tests\Integration\Payment;

use App\Http\Resources\Borrower\Payment\PaymentResource as BorrowerPaymentResource;
use App\Http\Resources\FCH\Common\Payment\PaymentResource as FchPaymentResource;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A payment row's "company" is the payer's company and nothing else - the list
 * shows it in its own column beside the person's name, so falling back to that
 * name would print it twice.
 */
#[CoversClass(FchPaymentResource::class)]
#[CoversClass(BorrowerPaymentResource::class)]
class PaymentCompanyTest extends TestCase
{
    use RefreshDatabase;

    private const RESOURCES = [FchPaymentResource::class, BorrowerPaymentResource::class];

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'full_name' => 'Rita Reseller',
            'company_name' => 'Reseller LLC',
            'email' => 'reseller@example.test',
        ]);
    }

    private function borrower(?string $businessName): Borrower
    {
        return Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Bo',
            'last_name' => 'Rower',
            'business_name' => $businessName,
            'email' => 'bo-' . uniqid() . '@example.test',
        ]);
    }

    private function paymentBy(string $entityType, int $entityId, ?int $borrowerId = null): Payment
    {
        $invoice = Invoice::create([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $this->partner->id,
            'borrower_id' => $borrowerId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payment_amount' => 100,
            'status' => Invoice::STATUS_PAID,
            'currency' => 'USD',
        ]);

        return Payment::create([
            'invoice_id' => $invoice->id,
            'partner_id' => $this->partner->id,
            'borrower_id' => $borrowerId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'amount' => 100,
            'currency' => 'USD',
            'transaction_id' => 'TX-' . uniqid(),
            'status' => Payment::STATUS_SUCCEEDED,
        ])->fresh();
    }

    private function company(string $resource, Payment $payment): mixed
    {
        return (new $resource($payment))->resolve(Request::create('/'))['company'];
    }

    #[Test]
    public function a_borrowers_payment_carries_their_business_name(): void
    {
        $borrower = $this->borrower('Bo Co');
        $payment = $this->paymentBy(Borrower::class, $borrower->id, $borrower->id);

        foreach (self::RESOURCES as $resource) {
            $this->assertSame('Bo Co', $this->company($resource, $payment), $resource);
        }
    }

    #[Test]
    public function a_borrower_with_no_business_has_no_company(): void
    {
        $borrower = $this->borrower(null);
        $payment = $this->paymentBy(Borrower::class, $borrower->id, $borrower->id);

        foreach (self::RESOURCES as $resource) {
            $this->assertNull($this->company($resource, $payment), $resource);
        }
    }

    #[Test]
    public function a_partners_payment_carries_the_company_name(): void
    {
        $payment = $this->paymentBy(Partner::class, $this->partner->id);

        foreach (self::RESOURCES as $resource) {
            $this->assertSame('Reseller LLC', $this->company($resource, $payment), $resource);
        }
    }
}
