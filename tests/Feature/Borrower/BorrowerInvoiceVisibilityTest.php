<?php

namespace Tests\Feature\Borrower;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\Borrower\Invoice\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-023: a borrower saw staff commission-payout invoices in their own
 * Invoices list, and those payouts were counted in their totals.
 *
 * A payout invoice is stamped with the deal's borrower_id for traceability, but
 * its payee is a partner or a staff user. The borrower queries matched on
 * borrower_id alone.
 */
#[CoversClass(InvoiceService::class)]
class BorrowerInvoiceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;
    private Borrower $borrower;
    private User $borrowerUser;
    private Invoice $own;
    private Invoice $payout;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host LLC',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $this->partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Michael',
            'last_name' => 'Chen',
            'email' => 'michael@example.test',
        ]);

        $this->borrowerUser = User::create([
            'name' => 'Michael Chen',
            'email' => 'michael@example.test',
            'password' => Hash::make('secret-password'),
            'status' => User::STATUS['active'],
            'user_type' => User::BORROWER,
            'partner_id' => $this->partner->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
        ]);

        $staff = User::create([
            'name' => 'Wonderwoman',
            'email' => 'staff@example.test',
            'password' => Hash::make('secret-password'),
            'status' => User::STATUS['active'],
            'user_type' => User::ADMIN,
            'partner_id' => $this->partner->id,
        ]);

        $this->own = $this->invoice('INV-OWN', Invoice::INVOICE_TYPE_ENROLLMENT, Borrower::class, $this->borrower->id, 4997);
        $this->payout = $this->invoice('INV-PAYOUT', Invoice::INVOICE_TYPE_COMMISSION_PAYOUT, User::class, $staff->id, 1000);

        $this->actingAs($this->borrowerUser);
    }

    private function invoice(string $ref, string $type, string $entityType, int $entityId, float $amount): Invoice
    {
        return Invoice::create([
            'invoice_id' => $ref,
            'partner_id' => $this->partner->id,
            'borrower_id' => $this->borrower->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'customer_name' => 'Someone',
            'email' => 'someone@example.test',
            'payment_amount' => $amount,
            'currency' => 'USD',
            'status' => Invoice::STATUS_PAID,
            'type' => $type,
        ]);
    }

    #[Test]
    public function the_invoice_list_and_its_totals_leave_out_commission_payouts(): void
    {
        $response = app(InvoiceService::class)->list(['page' => 1, 'per_page' => 10]);
        $body = $response->getData(true);

        $refs = collect($body['data'])->pluck('invoice_id')->all();

        $this->assertContains('INV-OWN', $refs);
        $this->assertNotContains('INV-PAYOUT', $refs);
    }

    #[Test]
    public function the_stats_leave_out_commission_payouts(): void
    {
        $stats = app(InvoiceService::class)->getStats($this->borrower->id, $this->partner->id);

        $this->assertSame(currencyFormat(4997), $stats['invoices_sent']);
        $this->assertSame(currencyFormat(4997), $stats['paid_invoices']);
    }

    #[Test]
    public function a_payout_invoice_cannot_be_opened_or_downloaded_by_the_borrower(): void
    {
        $service = app(InvoiceService::class);

        $this->assertNull($service->find($this->payout->id));
        $this->assertNotNull($service->find($this->own->id));
    }

    #[Test]
    public function an_unpaid_payout_is_not_offered_to_the_borrower_to_pay(): void
    {
        $this->payout->update(['status' => Invoice::STATUS_UNPAID]);

        $unpaid = app(InvoiceService::class)->getUnpaidInvoices($this->borrower->id);

        $this->assertFalse($unpaid->contains('id', $this->payout->id));
    }
}
