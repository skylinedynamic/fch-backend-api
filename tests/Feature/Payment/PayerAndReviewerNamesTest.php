<?php

namespace Tests\Feature\Payment;

use App\Http\Resources\FCH\Invoice\InvoiceDetailResource;
use App\Http\Resources\FCH\Common\Payment\PaymentResource;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-202 keeps who paid, approved or rejected a payment as user ids
 * (payments.paid_by / approved_by / rejected_by, invoices.paid_by). The
 * Payment and Invoice details screens show those people by their current name.
 * A payment from before then has no ids, so Approved By / Rejected By fall back
 * to the name written into its details at the time.
 */
#[CoversClass(PaymentResource::class)]
#[CoversClass(InvoiceDetailResource::class)]
class PayerAndReviewerNamesTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private User $hostAdmin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->hostAdmin = $this->makePartnerAdmin($this->host, [], ['name' => 'Hana Host']);
        $this->staff = $this->makeStaffUser($this->partner, [], ['name' => 'Sam Staff']);
        $this->actingAsUser($this->hostAdmin);
    }

    private function invoice(array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $this->partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $this->partner->id,
            'customer_name' => $this->partner->company_name,
            'email' => $this->partner->email,
            'payment_amount' => 800.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_PAID,
            'type' => Invoice::INVOICE_TYPE_BORROWER_FEE,
            'is_partner_paying' => true,
        ], $attributes));
    }

    private function payment(Invoice $invoice, array $attributes = []): Payment
    {
        return Payment::create(array_merge([
            'partner_id' => $invoice->partner_id,
            'invoice_id' => $invoice->id,
            'entity_type' => $invoice->entity_type,
            'entity_id' => $invoice->entity_id,
            'transaction_id' => 'TX-' . uniqid(),
            'amount' => $invoice->payment_amount,
            'currency' => 'USD',
            'status' => Payment::STATUS_SUCCEEDED,
            'is_partner_paying' => true,
            'transaction_date' => now(),
        ], $attributes));
    }

    #[Test]
    public function a_payment_names_who_paid_and_who_approved_it(): void
    {
        $payment = $this->payment($this->invoice(), ['paid_by' => $this->staff->id, 'approved_by' => $this->hostAdmin->id, 'is_manual' => true]);

        $this->getJson("/api/v1/common/payments/{$payment->id}")->assertOk()
            ->assertJsonPath('data.paid_by_name', 'Sam Staff')
            ->assertJsonPath('data.approved_by_name', 'Hana Host')
            ->assertJsonPath('data.rejected_by_name', null);
    }

    #[Test]
    public function a_rejected_payment_names_who_rejected_it(): void
    {
        $payment = $this->payment($this->invoice(['status' => Invoice::STATUS_UNPAID]), [
            'paid_by' => $this->staff->id, 'rejected_by' => $this->hostAdmin->id, 'is_manual' => true, 'status' => Payment::STATUS_FAILED,
        ]);

        $this->getJson("/api/v1/common/payments/{$payment->id}")->assertOk()
            ->assertJsonPath('data.rejected_by_name', 'Hana Host')
            ->assertJsonPath('data.approved_by_name', null);
    }

    #[Test]
    public function the_current_name_is_shown_not_the_one_at_the_time(): void
    {
        $payment = $this->payment($this->invoice(), ['paid_by' => $this->staff->id]);
        $this->staff->update(['name' => 'Samantha Staff']);

        $this->getJson("/api/v1/common/payments/{$payment->id}")->assertOk()
            ->assertJsonPath('data.paid_by_name', 'Samantha Staff');
    }

    #[Test]
    public function an_older_payment_falls_back_to_the_names_in_its_details(): void
    {
        $payment = $this->payment($this->invoice(), [
            'is_manual' => true,
            'details' => ['approved_by' => 'Old Approver', 'rejected_by' => 'Old Rejecter'],
        ]);

        $this->getJson("/api/v1/common/payments/{$payment->id}")->assertOk()
            ->assertJsonPath('data.paid_by_name', null)
            ->assertJsonPath('data.approved_by_name', 'Old Approver')
            ->assertJsonPath('data.rejected_by_name', 'Old Rejecter');
    }

    #[Test]
    public function a_paid_invoice_names_who_paid_it(): void
    {
        $invoice = $this->invoice(['paid_by' => $this->staff->id]);

        $this->getJson("/api/v1/common/invoices/{$invoice->id}")->assertOk()
            ->assertJsonPath('data.paid_by_name', 'Sam Staff');
    }

    #[Test]
    public function an_invoice_nobody_has_paid_names_nobody(): void
    {
        $invoice = $this->invoice(['status' => Invoice::STATUS_UNPAID]);

        $this->getJson("/api/v1/common/invoices/{$invoice->id}")->assertOk()
            ->assertJsonPath('data.paid_by_name', null);
    }
}
