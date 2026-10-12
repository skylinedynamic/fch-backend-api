<?php

namespace Tests\Feature\Payment;

use App\Http\Controllers\Api\V1\FCH\Common\Invoice\InvoiceController;
use App\Http\Controllers\Api\V1\FCH\Common\Payment\PaymentController;
use App\Mail\Invoice\InvoiceMail;
use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The partner portal's billing screens: /common/invoices/* and
 * /common/payments/* - who sees which invoices and payments, and who may
 * approve or reject a manual (bank transfer / cash) payment.
 *
 * Visibility: a non-host partner sees its own invoices and its borrowers'.
 * The host sees what partners owe it plus its own borrowers', but not a
 * white-label partner's borrowers - that money goes to the partner.
 */
#[CoversClass(InvoiceController::class)]
#[CoversClass(PaymentController::class)]
class PartnerInvoicesAndPaymentsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private Partner $otherPartner;
    private User $hostAdmin;
    private User $partnerAdmin;
    private User $partnerBorrower;
    private User $hostBorrower;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->otherPartner = $this->makeCertifiedPartner();
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
        $this->partnerAdmin = $this->makePartnerAdmin($this->partner);
        $this->partnerBorrower = $this->makeBorrowerUser($this->partner);
        $this->hostBorrower = $this->makeBorrowerUser($this->host);
    }

    private function borrowerInvoice(User $borrowerUser, array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $borrowerUser->partner_id,
            'borrower_id' => $borrowerUser->entity_id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrowerUser->entity_id,
            'customer_name' => $borrowerUser->name,
            'email' => $borrowerUser->email,
            'payment_amount' => 300.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_INSTALLMENT,
            'is_partner_paying' => false,
        ], $attributes));
    }

    /** What a partner owes the host. */
    private function partnerInvoice(Partner $partner): Invoice
    {
        return Invoice::create([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $partner->id,
            'entity_type' => Partner::class,
            'entity_id' => $partner->id,
            'customer_name' => $partner->company_name,
            'email' => $partner->email,
            'payment_amount' => 4997.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_ENROLLMENT,
            'is_partner_paying' => true,
        ]);
    }

    private function manualPayment(Invoice $invoice, string $status = Payment::STATUS_PROCESSING): Payment
    {
        $invoice->update(['status' => Invoice::STATUS_PROCESSING]);

        return Payment::create([
            'partner_id' => $invoice->partner_id,
            'borrower_id' => $invoice->borrower_id,
            'invoice_id' => $invoice->id,
            'entity_type' => $invoice->entity_type,
            'entity_id' => $invoice->entity_id,
            'transaction_id' => 'MANUAL-' . uniqid(),
            'amount' => $invoice->payment_amount,
            'currency' => 'USD',
            'status' => $status,
            'is_manual' => true,
            'is_partner_paying' => (bool) $invoice->is_partner_paying,
            'transaction_date' => now(),
        ]);
    }

    private function listedInvoiceIds(): array
    {
        return collect($this->postJson('/api/v1/common/invoices/fetch-invoices')->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
    }

    #[Test]
    public function every_route_needs_a_signed_in_user(): void
    {
        $this->postJson('/api/v1/common/invoices/fetch-invoices')->assertUnauthorized();
        $this->getJson('/api/v1/common/invoices/1')->assertUnauthorized();
        $this->getJson('/api/v1/common/invoices/1/download')->assertUnauthorized();
        $this->postJson('/api/v1/common/invoices/1/send')->assertUnauthorized();
        $this->getJson('/api/v1/common/payments/1')->assertUnauthorized();
        $this->postJson('/api/v1/common/payments/1/approve')->assertUnauthorized();
        $this->postJson('/api/v1/common/payments/1/reject', ['reason' => 'x'])->assertUnauthorized();
    }

    #[Test]
    public function a_partner_sees_its_own_invoices_and_its_borrowers_but_no_one_elses(): void
    {
        $own = $this->partnerInvoice($this->partner);
        $client = $this->borrowerInvoice($this->partnerBorrower);
        $this->partnerInvoice($this->otherPartner);
        $this->borrowerInvoice($this->hostBorrower);
        $this->actingAsUser($this->partnerAdmin);

        $this->assertSame([$own->id, $client->id], $this->listedInvoiceIds());
    }

    #[Test]
    public function the_host_sees_what_partners_owe_it_and_its_own_borrowers_but_not_a_partners_borrowers(): void
    {
        $owed = $this->partnerInvoice($this->partner);
        $ownClient = $this->borrowerInvoice($this->hostBorrower);
        $this->borrowerInvoice($this->partnerBorrower);
        $this->actingAsUser($this->hostAdmin);

        $this->assertSame([$owed->id, $ownClient->id], $this->listedInvoiceIds());
    }

    #[Test]
    public function an_invoice_outside_my_scope_is_not_found(): void
    {
        $theirs = $this->borrowerInvoice($this->makeBorrowerUser($this->otherPartner));
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/invoices/{$theirs->id}")->assertNotFound();
        $this->getJson("/api/v1/common/invoices/{$theirs->id}/download")->assertNotFound();
        $this->postJson("/api/v1/common/invoices/{$theirs->id}/send")->assertStatus(500);

        Mail::assertNothingOutgoing();
    }

    #[Test]
    public function i_can_open_and_download_my_borrowers_invoice(): void
    {
        $invoice = $this->borrowerInvoice($this->partnerBorrower);
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/invoices/{$invoice->id}")->assertOk()->assertJsonPath('data.id', $invoice->id);
        $this->getJson("/api/v1/common/invoices/{$invoice->invoice_id}")->assertOk()->assertJsonPath('data.id', $invoice->id);

        $pdf = $this->getJson("/api/v1/common/invoices/{$invoice->id}/download")->assertOk()->json('data.file');
        $this->assertStringStartsWith('%PDF', base64_decode($pdf));
    }

    #[Test]
    public function sending_a_borrower_invoice_mails_it_to_the_borrower(): void
    {
        $invoice = $this->borrowerInvoice($this->partnerBorrower);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/invoices/{$invoice->id}/send")->assertOk();

        Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->hasTo($this->partnerBorrower->email));
    }

    #[Test]
    public function the_invoice_list_validates_its_filters(): void
    {
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson('/api/v1/common/invoices/fetch-invoices', ['sort_order' => 'up'])->assertStatus(422);
        $this->postJson('/api/v1/common/invoices/fetch-invoices', ['payment_amount' => 'lots'])->assertStatus(422);
    }

    #[Test]
    public function a_partner_approves_its_borrowers_manual_payment_and_the_invoice_becomes_paid(): void
    {
        $payment = $this->manualPayment($this->borrowerInvoice($this->partnerBorrower));
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/payments/{$payment->id}/approve", ['note' => 'Seen on statement'])
            ->assertOk()
            ->assertJsonPath('status', true);

        $payment->refresh();
        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->status);
        $this->assertSame('approved', $payment->details['decision']);
        $this->assertSame($this->partnerAdmin->id, $payment->details['approved_by_id']);
        $this->assertSame(Invoice::STATUS_PAID, $payment->invoice->fresh()->status);
    }

    #[Test]
    public function a_partner_rejects_a_manual_payment_with_a_reason_and_the_invoice_is_unpaid_again(): void
    {
        $payment = $this->manualPayment($this->borrowerInvoice($this->partnerBorrower));
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/payments/{$payment->id}/reject", ['reason' => 'No such transfer'])->assertOk();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_REJECTED, $payment->status);
        $this->assertSame('No such transfer', $payment->details['rejection_reason']);
        $this->assertSame(Invoice::STATUS_UNPAID, $payment->invoice->fresh()->status);
    }

    #[Test]
    public function a_rejection_needs_a_reason(): void
    {
        $payment = $this->manualPayment($this->borrowerInvoice($this->partnerBorrower));
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/payments/{$payment->id}/reject", [])->assertStatus(422);

        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
    }

    #[Test]
    public function only_a_processing_payment_can_be_approved_or_rejected(): void
    {
        $payment = $this->manualPayment($this->borrowerInvoice($this->partnerBorrower), Payment::STATUS_SUCCEEDED);
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/payments/{$payment->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', __('payment.only_processing_can_be_approved'));
        $this->postJson("/api/v1/common/payments/{$payment->id}/reject", ['reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('payment.only_processing_can_be_rejected'));
    }

    #[Test]
    public function a_partner_cannot_approve_another_partners_payment(): void
    {
        $payment = $this->manualPayment($this->borrowerInvoice($this->makeBorrowerUser($this->otherPartner)));
        $this->actingAsUser($this->partnerAdmin);

        $this->postJson("/api/v1/common/payments/{$payment->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', __('payment.unauthorized_approve'));

        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
    }

    #[Test]
    public function a_borrower_cannot_approve_their_own_payment(): void
    {
        $payment = $this->manualPayment($this->borrowerInvoice($this->partnerBorrower));
        $this->actingAsUser($this->partnerBorrower);

        $this->postJson("/api/v1/common/payments/{$payment->id}/approve")->assertStatus(422);

        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
    }

    #[Test]
    public function the_host_approves_what_a_partner_paid_it(): void
    {
        $payment = $this->manualPayment($this->partnerInvoice($this->partner));
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/payments/{$payment->id}/approve")->assertOk();

        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
    }

    #[Test]
    public function the_host_approves_its_own_borrowers_payment(): void
    {
        $payment = $this->manualPayment($this->borrowerInvoice($this->hostBorrower));
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/payments/{$payment->id}/approve")->assertOk();
    }

    /**
     * Defect: canApproveForUser() returned true for the host on any payment,
     * although its own comment - and the list scope, which hides these from
     * the host - say a white-label partner's borrower payments belong to that
     * partner. The host could confirm money it never received.
     */
    #[Test]
    public function the_host_cannot_approve_a_payment_a_borrower_made_to_their_partner(): void
    {
        $payment = $this->manualPayment($this->borrowerInvoice($this->partnerBorrower));
        $this->actingAsUser($this->hostAdmin);

        $this->postJson("/api/v1/common/payments/{$payment->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('message', __('payment.unauthorized_approve'));

        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
    }

    #[Test]
    public function a_payment_outside_my_scope_is_not_found(): void
    {
        $payment = $this->manualPayment($this->borrowerInvoice($this->makeBorrowerUser($this->otherPartner)));
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/payments/{$payment->id}")->assertNotFound();
    }

    #[Test]
    public function i_can_open_my_borrowers_payment(): void
    {
        $payment = $this->manualPayment($this->borrowerInvoice($this->partnerBorrower));
        $this->actingAsUser($this->partnerAdmin);

        $this->getJson("/api/v1/common/payments/{$payment->id}")->assertOk()->assertJsonPath('data.id', $payment->id);
    }
}
