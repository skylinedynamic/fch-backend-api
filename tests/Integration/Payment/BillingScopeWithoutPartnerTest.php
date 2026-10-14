<?php

namespace Tests\Integration\Payment;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\User\User;
use App\Services\FCH\Common\Invoice\InvoiceService;
use App\Services\FCH\Common\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The invoice and payment lists, scoped for a user whose partner cannot be
 * found. The route middleware already refuses such a user; this is the second
 * line, for any caller that reaches the services another way.
 *
 * Defect (findings O6): applyRbacScope() fell back to the host when the user
 * had no partner, so the user saw what the host sees - and with no host on
 * record no filter was applied at all. It now shows nothing.
 */
#[CoversClass(InvoiceService::class)]
#[CoversClass(PaymentService::class)]
class BillingScopeWithoutPartnerTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private function seedBilling(Partner $partner): void
    {
        $borrower = $this->makeBorrower($partner);

        foreach ([[Partner::class, $partner->id, true], [Borrower::class, $borrower->id, false]] as [$type, $id, $partnerPaying]) {
            $invoice = Invoice::create([
                'invoice_id' => 'INV-' . uniqid(),
                'partner_id' => $partner->id,
                'borrower_id' => $type === Borrower::class ? $borrower->id : null,
                'entity_type' => $type,
                'entity_id' => $id,
                'customer_name' => 'Customer',
                'email' => 'c-' . uniqid() . '@example.test',
                'payment_amount' => 100.00,
                'currency' => 'USD',
                'status' => Invoice::STATUS_UNPAID,
                'type' => Invoice::INVOICE_TYPE_INSTALLMENT,
                'is_partner_paying' => $partnerPaying,
            ]);

            Payment::create([
                'partner_id' => $partner->id,
                'borrower_id' => $invoice->borrower_id,
                'invoice_id' => $invoice->id,
                'entity_type' => $type,
                'entity_id' => $id,
                'transaction_id' => 'TX-' . uniqid(),
                'amount' => 100.00,
                'currency' => 'USD',
                'status' => Payment::STATUS_PROCESSING,
                'is_partner_paying' => $partnerPaying,
                'transaction_date' => now(),
            ]);
        }
    }

    private function listedInvoices(User $user): array
    {
        Auth::setUser($user);

        return app(InvoiceService::class)->getInvoices([])->getData(true)['data'];
    }

    private function listedPayments(User $user): array
    {
        Auth::setUser($user);

        return app(PaymentService::class)->list([])->getData(true)['data'];
    }

    #[Test]
    public function a_user_with_no_partner_sees_no_invoices_or_payments(): void
    {
        $host = $this->makeHostPartner();
        $this->seedBilling($host);
        $this->seedBilling($this->makeWhiteLabelPartner());
        $orphan = $this->makeUser(['user_type' => User::ADMIN, 'partner_id' => null]);

        $this->assertSame([], $this->listedInvoices($orphan));
        $this->assertSame([], $this->listedPayments($orphan));
    }

    #[Test]
    public function with_no_host_on_record_a_user_with_no_partner_still_sees_nothing(): void
    {
        $this->seedBilling($this->makeWhiteLabelPartner());
        $orphan = $this->makeUser(['user_type' => User::ADMIN, 'partner_id' => null]);

        $this->assertSame([], $this->listedInvoices($orphan));
        $this->assertSame([], $this->listedPayments($orphan));
    }

    #[Test]
    public function the_host_still_sees_what_partners_owe_it(): void
    {
        $host = $this->makeHostPartner();
        $this->seedBilling($this->makeWhiteLabelPartner());

        $this->assertNotEmpty($this->listedInvoices($this->makePartnerAdmin($host)));
        $this->assertNotEmpty($this->listedPayments($this->makePartnerAdmin($host)));
    }
}
