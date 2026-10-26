<?php

namespace Tests\Feature\Invoice;

use App\Http\Resources\Borrower\Invoice\InvoiceResource as BorrowerInvoiceResource;
use App\Http\Resources\FCH\Invoice\InvoiceDetailResource;
use App\Http\Resources\FCH\Invoice\InvoiceResource;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-122: the invoice details modal dated a down-payment invoice 21 Oct while
 * the list and the PDF said 20 Oct - the API shifted created_at into the
 * viewer's time zone (toTimezone) and the PDF did not. An invoice's date is
 * now its stored date everywhere (toDateOnly), whatever the viewer's zone.
 */
#[CoversClass(InvoiceResource::class)]
#[CoversClass(InvoiceDetailResource::class)]
#[CoversClass(BorrowerInvoiceResource::class)]
class InvoiceDateTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        Carbon::setTestNow('2026-10-20 22:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(array $attributes = []): Invoice
    {
        $partner = $this->makeWhiteLabelPartner();

        return Invoice::create(array_merge([
            'invoice_id' => 'INV-20261020009',
            'partner_id' => $partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'customer_name' => $partner->company_name,
            'email' => $partner->email,
            'payment_amount' => 1000.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
            'is_partner_paying' => true,
        ], $attributes));
    }

    /** @return iterable<string, array{string}> */
    public static function zones(): iterable
    {
        yield 'ahead of UTC (Dhaka, already 21 Oct)' => ['Asia/Dhaka'];
        yield 'behind UTC (Chicago)' => ['America/Chicago'];
    }

    #[Test]
    #[DataProvider('zones')]
    public function the_details_show_the_invoices_own_date(string $zone): void
    {
        $invoice = $this->invoice();
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson("/api/v1/common/invoices/{$invoice->id}", ['X-Timezone' => $zone])
            ->assertOk()
            ->assertJsonPath('data.created_at', '2026-10-20');
    }

    #[Test]
    #[DataProvider('zones')]
    public function the_list_shows_the_invoices_own_date(string $zone): void
    {
        $invoice = $this->invoice();
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $row = collect($this->postJson('/api/v1/common/invoices/fetch-invoices', [], ['X-Timezone' => $zone])->assertOk()->json('data'))
            ->firstWhere('id', $invoice->id);

        $this->assertSame('2026-10-20', $row['created_at']);
    }

    #[Test]
    #[DataProvider('zones')]
    public function the_borrower_portal_list_shows_the_invoices_own_date(string $zone): void
    {
        $partner = $this->makeWhiteLabelPartner();
        $borrowerUser = $this->makeBorrowerUser($partner);
        $invoice = $this->invoice([
            'partner_id' => $partner->id,
            'borrower_id' => $borrowerUser->entity_id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrowerUser->entity_id,
            'is_partner_paying' => false,
        ]);
        $this->actingAsUser($borrowerUser);

        $row = collect($this->postJson('/api/v1/borrower/invoice', [], ['X-Timezone' => $zone])->assertOk()->json('data'))
            ->firstWhere('id', $invoice->id);

        $this->assertSame('2026-10-20', $row['created_at']);
    }
}
