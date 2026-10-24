<?php

namespace Tests\Integration\Invoice;

use App\Http\Resources\FCH\Invoice\InvoiceResource;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Invoice\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin invoice list names each row after the person paying it, with their
 * company beside it - read live from partners/borrowers.
 *
 * invoices.customer_name is a snapshot written at creation, and some writers
 * store the partner's COMPANY there (a borrower fee, a user seat), so the list
 * no longer reads it while the payer still exists.
 */
#[CoversClass(InvoiceResource::class)]
#[CoversClass(InvoiceService::class)]
class InvoiceListTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;
    private Partner $reseller;
    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'full_name' => 'Hal Host',
            'company_name' => 'Host LLC',
            'email' => 'host@example.test',
        ]);

        $this->reseller = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'full_name' => 'Rita Reseller',
            'company_name' => 'Reseller LLC',
            'email' => 'reseller@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $this->host->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Bo',
            'last_name' => 'Rower',
            'business_name' => 'Bo Co',
            'email' => 'bo@example.test',
        ]);
    }

    private function hostAdmin(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Host Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('secret'),
            'user_type' => User::ADMIN,
            'partner_id' => $this->host->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::findOrFail($id);
    }

    private function borrowerFee(): Invoice
    {
        // The shape EnrollmentService writes: the company snapshotted as the
        // customer name, the borrower attached for traceability.
        return Invoice::create([
            'invoice_id' => 'INV-FEE',
            'partner_id' => $this->reseller->id,
            'borrower_id' => $this->borrower->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->reseller->id,
            'customer_name' => 'Reseller LLC',
            'payment_amount' => 800,
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
            'is_partner_paying' => true,
            'currency' => 'USD',
        ]);
    }

    private function enrollment(): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-ENR',
            'partner_id' => $this->host->id,
            'borrower_id' => $this->borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            'customer_name' => 'Bo Rower',
            'payment_amount' => 2497,
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
            'currency' => 'USD',
        ]);
    }

    /** @return array<string, array<string, mixed>> rows keyed by invoice_id */
    private function listAsHost(): array
    {
        $this->actingAs($this->hostAdmin(), 'api');

        $rows = app(InvoiceService::class)->getInvoices([])->getData(true)['data'];

        return collect($rows)->keyBy('invoice_id')->all();
    }

    #[Test]
    public function a_row_is_named_after_the_person_paying_with_their_company_beside_it(): void
    {
        $this->borrowerFee();
        $this->enrollment();

        $rows = $this->listAsHost();

        $this->assertSame('Rita Reseller', $rows['INV-FEE']['customer_name']);
        $this->assertSame('Reseller LLC', $rows['INV-FEE']['company_name']);

        $this->assertSame('Bo Rower', $rows['INV-ENR']['customer_name']);
        $this->assertSame('Bo Co', $rows['INV-ENR']['company_name']);
    }

    #[Test]
    public function a_payer_that_no_longer_exists_keeps_the_saved_name(): void
    {
        $invoice = $this->borrowerFee();
        $invoice->update(['entity_id' => 999999, 'customer_name' => 'Gone Partner']);

        $row = (new InvoiceResource($invoice->fresh()))->resolve(Request::instance());

        $this->assertSame('Gone Partner', $row['customer_name']);
        $this->assertNull($row['company_name']);
    }
}
