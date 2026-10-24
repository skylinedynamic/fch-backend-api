<?php

namespace Tests\Integration\Invoice;

use App\Http\Resources\Borrower\Invoice\InvoiceDetailResource as BorrowerInvoiceDetailResource;
use App\Http\Resources\FCH\Invoice\InvoiceDetailResource as FchInvoiceDetailResource;
use App\Models\Borrower\Borrower;
use App\Models\Employee\Employee;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Invoice\InvoicePartyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who an invoice is billed from and billed to.
 *
 * entity_type/entity_id is whoever PAYS, so it is always "Billed To". "Billed
 * From" is whoever is paid:
 *   - a borrower_fee is the partner's debt to the host  -> host
 *   - any invoice a Partner pays                        -> host
 *   - anything else                                     -> invoices.partner_id
 */
#[CoversClass(InvoicePartyResolver::class)]
class InvoicePartyResolverTest extends TestCase
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
            'phone_number' => '555-0100',
            'address_line_1' => '1 Host Way',
            'address_line_2' => 'Suite 100',
            'city' => 'Irvine',
            'state' => 'CA',
            'postal_code' => '92618',
            'country' => 'United States',
        ]);

        $this->reseller = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'full_name' => 'Rita Reseller',
            'company_name' => 'Reseller LLC',
            'email' => 'reseller@example.test',
            'phone_number' => '555-0200',
            'address_line_1' => '2 Reseller Rd',
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '73301',
            'country' => 'United States',
        ]);

        $this->borrower = Borrower::create([
            'partner_id' => $this->reseller->id,
            'status' => Borrower::STATUS_ONBOARDING,
            'first_name' => 'Bo',
            'last_name' => 'Rower',
            'email' => 'bo@example.test',
            'phone_number' => '555-0300',
            'business_name' => 'Bo Co',
            'business_address' => '9 Market St',
            'business_city' => 'Tampa',
            'business_state' => 'FL',
            'business_postal_code' => '33602',
            'business_country' => 'United States',
            'home_address' => '3 Home St',
            'city' => 'Miami',
            'state' => 'FL',
            'postal_code' => '33101',
            'country' => 'Canada',
        ]);
    }

    private function invoice(array $attributes): Invoice
    {
        return Invoice::create(array_merge([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $this->reseller->id,
            'payment_amount' => 100.00,
            'status' => Invoice::STATUS_UNPAID,
            'currency' => 'USD',
        ], $attributes));
    }

    private function resolve(Invoice $invoice): array
    {
        return app(InvoicePartyResolver::class)->resolve($invoice->fresh());
    }

    #[Test]
    public function a_partner_paid_invoice_is_billed_from_the_host_to_the_partner(): void
    {
        $parties = $this->resolve($this->invoice([
            'entity_type' => Partner::class,
            'entity_id' => $this->reseller->id,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
        ]));

        $this->assertSame([
            'name' => 'Hal Host',
            'company_name' => 'Host LLC',
            'email' => 'host@example.test',
            'phone' => '555-0100',
            'address' => '1 Host Way, Suite 100',
            'city' => 'Irvine',
            'state' => 'CA',
            'postal_code' => '92618',
            'country' => 'United States',
        ], $parties['billed_from']);

        $this->assertSame([
            'name' => 'Rita Reseller',
            'company_name' => 'Reseller LLC',
            'email' => 'reseller@example.test',
            'phone' => '555-0200',
            'address' => '2 Reseller Rd',
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '73301',
            'country' => 'United States',
        ], $parties['billed_to']);
    }

    #[Test]
    public function a_borrower_paid_invoice_is_billed_from_its_partner_to_the_borrowers_company(): void
    {
        $parties = $this->resolve($this->invoice([
            'borrower_id' => $this->borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
        ]));

        $this->assertSame('Reseller LLC', $parties['billed_from']['company_name']);
        $this->assertSame('Rita Reseller', $parties['billed_from']['name']);

        $this->assertSame([
            'name' => 'Bo Rower',
            'company_name' => 'Bo Co',
            'email' => 'bo@example.test',
            'phone' => '555-0300',
            'address' => '9 Market St',
            'city' => 'Tampa',
            'state' => 'FL',
            'postal_code' => '33602',
            'country' => 'United States',
        ], $parties['billed_to']);
    }

    #[Test]
    public function a_borrower_without_a_business_address_is_billed_at_their_home_address(): void
    {
        $this->borrower->update([
            'business_address' => null,
            'business_city' => null,
            'business_state' => null,
            'business_postal_code' => null,
            'business_country' => null,
        ]);

        $billedTo = $this->resolve($this->invoice([
            'borrower_id' => $this->borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
        ]))['billed_to'];

        $this->assertSame('Bo Co', $billedTo['company_name']);
        $this->assertSame('3 Home St', $billedTo['address']);
        $this->assertSame('Miami', $billedTo['city']);
        $this->assertSame('FL', $billedTo['state']);
        $this->assertSame('33101', $billedTo['postal_code']);
        $this->assertSame('Canada', $billedTo['country']);
    }

    #[Test]
    public function a_borrower_fee_is_billed_to_the_partner_not_the_borrower_it_is_for(): void
    {
        // The real shape EnrollmentService writes: borrower_id is set for
        // traceability, but the partner is the one paying.
        $parties = $this->resolve($this->invoice([
            'borrower_id' => $this->borrower->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->reseller->id,
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
            'is_partner_paying' => true,
        ]));

        $this->assertSame('Host LLC', $parties['billed_from']['company_name']);
        $this->assertSame('Reseller LLC', $parties['billed_to']['company_name']);
    }

    #[Test]
    public function a_borrower_fee_is_billed_from_the_host_whoever_its_entity_is(): void
    {
        $parties = $this->resolve($this->invoice([
            'borrower_id' => $this->borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
        ]));

        $this->assertSame('Host LLC', $parties['billed_from']['company_name']);
        $this->assertSame('Bo Rower', $parties['billed_to']['name']);
    }

    #[Test]
    public function an_invoice_with_no_partner_is_billed_from_the_host(): void
    {
        $parties = $this->resolve($this->invoice([
            'partner_id' => null,
            'borrower_id' => $this->borrower->id,
            'entity_type' => Borrower::class,
            'entity_id' => $this->borrower->id,
        ]));

        $this->assertSame('Host LLC', $parties['billed_from']['company_name']);
    }

    #[Test]
    public function an_employee_payer_is_billed_with_their_own_contact_details(): void
    {
        $employee = Employee::create([
            'partner_id' => $this->reseller->id,
            'first_name' => 'Eve Employee',
            'email' => 'eve@example.test',
            'phone_number_1' => '555-0400',
            'address' => '4 Staff Ln',
            'city' => 'Denver',
            'state' => 'CO',
            'postal_code' => '80014',
            'country' => 'United States',
        ]);

        $parties = $this->resolve($this->invoice([
            'entity_type' => Employee::class,
            'entity_id' => $employee->id,
            'type' => Invoice::INVOICE_TYPE_USER_SEAT,
        ]));

        $this->assertSame('Reseller LLC', $parties['billed_from']['company_name']);
        $this->assertSame([
            'name' => 'Eve Employee',
            'company_name' => null,
            'email' => 'eve@example.test',
            'phone' => '555-0400',
            'address' => '4 Staff Ln',
            'city' => 'Denver',
            'state' => 'CO',
            'postal_code' => '80014',
            'country' => 'United States',
        ], $parties['billed_to']);
    }

    #[Test]
    public function a_user_payer_is_billed_with_their_name_email_and_phone(): void
    {
        $user = User::create([
            'partner_id' => $this->host->id,
            'name' => 'Uma User',
            'email' => 'uma@example.test',
            'phone_number' => '555-0500',
            'password' => 'secret-password',
        ]);

        $billedTo = $this->resolve($this->invoice([
            'partner_id' => $this->host->id,
            'entity_type' => User::class,
            'entity_id' => $user->id,
            'type' => Invoice::INVOICE_TYPE_COMMISSION_PAYOUT,
        ]))['billed_to'];

        $this->assertSame('Uma User', $billedTo['name']);
        $this->assertSame('uma@example.test', $billedTo['email']);
        $this->assertSame('555-0500', $billedTo['phone']);
        $this->assertNull($billedTo['address']);
    }

    #[Test]
    public function a_payer_that_no_longer_exists_falls_back_to_the_details_saved_on_the_invoice(): void
    {
        $invoice = $this->invoice([
            'entity_type' => Borrower::class,
            'entity_id' => 999999,
            'customer_name' => 'Gone Borrower',
            'email' => 'gone@example.test',
            'country' => 'Canada',
        ]);

        $billedTo = $this->resolve($invoice)['billed_to'];

        $this->assertSame('Gone Borrower', $billedTo['name']);
        $this->assertSame('gone@example.test', $billedTo['email']);
        $this->assertSame('Canada', $billedTo['country']);
        $this->assertNull($billedTo['address']);
    }

    #[Test]
    public function both_invoice_detail_resources_expose_billed_from_and_billed_to(): void
    {
        $invoice = $this->invoice([
            'borrower_id' => $this->borrower->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->reseller->id,
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
        ])->fresh(['borrower', 'partner', 'subscription']);

        foreach ([BorrowerInvoiceDetailResource::class, FchInvoiceDetailResource::class] as $resource) {
            $data = (new $resource($invoice))->toArray(Request::create('/'));

            $this->assertSame('Host LLC', $data['billed_from']['company_name'], $resource);
            $this->assertSame('Reseller LLC', $data['billed_to']['company_name'], $resource);
        }
    }
}
