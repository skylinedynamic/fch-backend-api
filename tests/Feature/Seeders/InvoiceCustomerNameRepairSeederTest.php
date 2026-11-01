<?php

namespace Tests\Feature\Seeders;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use Database\Seeders\InvoiceCustomerNameRepairSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The borrower fee, the employee seat and the seat renewal used to save the
 * paying partner's COMPANY as invoices.customer_name. This one-off puts the
 * partner's own name back on those rows.
 */
#[CoversClass(InvoiceCustomerNameRepairSeeder::class)]
class InvoiceCustomerNameRepairSeederTest extends TestCase
{
    use RefreshDatabase;

    private Partner $reseller;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reseller = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'full_name' => 'Rita Reseller',
            'company_name' => 'Reseller LLC',
            'email' => 'reseller@example.test',
        ]);
    }

    private function partnerInvoice(string $customerName, ?Partner $partner = null): Invoice
    {
        $partner ??= $this->reseller;

        return Invoice::create([
            'invoice_id' => 'INV-' . ++$this->seq,
            'partner_id' => $partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'customer_name' => $customerName,
            'payment_amount' => 800,
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
            'currency' => 'USD',
        ]);
    }

    private function runSeeder(bool $dryRun = false): void
    {
        $seeder = new InvoiceCustomerNameRepairSeeder();
        $seeder->dryRun = $dryRun;
        $seeder->run();
    }

    #[Test]
    public function a_partner_invoice_saved_under_the_company_gets_the_partners_name(): void
    {
        $invoice = $this->partnerInvoice('Reseller LLC');

        $this->runSeeder();

        $this->assertSame('Rita Reseller', $invoice->fresh()->customer_name);
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        $invoice = $this->partnerInvoice('Reseller LLC');

        $this->runSeeder(dryRun: true);

        $this->assertSame('Reseller LLC', $invoice->fresh()->customer_name);
    }

    #[Test]
    public function a_row_already_in_a_persons_name_is_left_alone(): void
    {
        // Including a name from before the partner was renamed: that snapshot is
        // still a person, so it is not this repair's to rewrite.
        $current = $this->partnerInvoice('Rita Reseller');
        $older = $this->partnerInvoice('Rita Old-Name');

        $this->runSeeder();

        $this->assertSame('Rita Reseller', $current->fresh()->customer_name);
        $this->assertSame('Rita Old-Name', $older->fresh()->customer_name);
    }

    #[Test]
    public function a_borrowers_invoice_is_never_touched(): void
    {
        $borrower = Borrower::create([
            'partner_id' => $this->reseller->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Bo',
            'last_name' => 'Rower',
            'business_name' => 'Bo Co',
            'email' => 'bo@example.test',
        ]);

        $invoice = Invoice::create([
            'invoice_id' => 'INV-B',
            'partner_id' => $this->reseller->id,
            'borrower_id' => $borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrower->id,
            // Whatever is here, it is not a partner's company.
            'customer_name' => 'Reseller LLC',
            'payment_amount' => 2497,
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
            'currency' => 'USD',
        ]);

        $this->runSeeder();

        $this->assertSame('Reseller LLC', $invoice->fresh()->customer_name);
    }

    #[Test]
    public function a_partner_with_no_name_of_its_own_is_left_for_review_not_blanked(): void
    {
        $nameless = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'full_name' => null,
            'company_name' => 'Nameless LLC',
            'email' => 'nameless@example.test',
        ]);
        $invoice = $this->partnerInvoice('Nameless LLC', $nameless);

        $this->runSeeder();

        $this->assertSame('Nameless LLC', $invoice->fresh()->customer_name);
    }

    #[Test]
    public function a_partner_whose_company_is_its_own_name_needs_nothing(): void
    {
        $sole = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'full_name' => 'Sam Sole',
            'company_name' => 'Sam Sole',
            'email' => 'sam@example.test',
        ]);
        $invoice = $this->partnerInvoice('Sam Sole', $sole);

        $this->runSeeder();

        $this->assertSame('Sam Sole', $invoice->fresh()->customer_name);
    }
}
