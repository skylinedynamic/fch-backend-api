<?php

namespace Tests\Feature\Payment;

use App\Http\Controllers\Api\V1\Borrower\Invoice\InvoiceController;
use App\Http\Controllers\Api\V1\Borrower\Payment\PaymentController;
use App\Models\Borrower\Borrower;
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
 * The borrower portal's own billing records: /borrower/invoice* and
 * /borrower/payments*. A borrower sees their own invoices and payments and
 * nobody else's, and never the partner-paid fee invoices raised about them.
 */
#[CoversClass(InvoiceController::class)]
#[CoversClass(PaymentController::class)]
class BorrowerInvoicesAndPaymentsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $me;
    private User $neighbour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = $this->makeWhiteLabelPartner();
        $this->me = $this->makeBorrowerUser($this->partner);
        $this->neighbour = $this->makeBorrowerUser($this->partner);
    }

    private function invoiceFor(User $borrowerUser, array $attributes = []): Invoice
    {
        return Invoice::create(array_merge([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $borrowerUser->partner_id,
            'borrower_id' => $borrowerUser->entity_id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrowerUser->entity_id,
            'customer_name' => $borrowerUser->name,
            'email' => $borrowerUser->email,
            'payment_amount' => 250.00,
            'currency' => 'USD',
            'status' => Invoice::STATUS_UNPAID,
            'type' => Invoice::INVOICE_TYPE_INSTALLMENT,
            'is_partner_paying' => false,
        ], $attributes));
    }

    private function paymentFor(User $borrowerUser, Invoice $invoice): Payment
    {
        return Payment::create([
            'partner_id' => $borrowerUser->partner_id,
            'borrower_id' => $borrowerUser->entity_id,
            'invoice_id' => $invoice->id,
            'entity_type' => Borrower::class,
            'entity_id' => $borrowerUser->entity_id,
            'transaction_id' => 'TX-' . uniqid(),
            'amount' => $invoice->payment_amount,
            'currency' => 'USD',
            'status' => Payment::STATUS_SUCCEEDED,
            'transaction_date' => now(),
        ]);
    }

    #[Test]
    public function every_route_needs_a_signed_in_borrower(): void
    {
        $this->postJson('/api/v1/borrower/invoice')->assertUnauthorized();
        $this->getJson('/api/v1/borrower/invoice/check-unpaid-invoice')->assertUnauthorized();
        $this->getJson('/api/v1/borrower/invoice/1')->assertUnauthorized();
        $this->getJson('/api/v1/borrower/invoice/1/download')->assertUnauthorized();
        $this->postJson('/api/v1/borrower/payments/fetch-payments')->assertUnauthorized();
        $this->getJson('/api/v1/borrower/payments/1')->assertUnauthorized();

        $this->actingAsUser($this->makePartnerAdmin($this->partner));
        $this->postJson('/api/v1/borrower/invoice')->assertForbidden();
        $this->postJson('/api/v1/borrower/payments/fetch-payments')->assertForbidden();
    }

    #[Test]
    public function the_invoice_list_holds_only_my_own_borrower_invoices(): void
    {
        $mine = $this->invoiceFor($this->me);
        $this->invoiceFor($this->neighbour);
        $this->invoiceFor($this->me, ['is_partner_paying' => true, 'type' => Invoice::INVOICE_TYPE_BORROWER_FEE]);
        $this->actingAsUser($this->me);

        $ids = collect($this->postJson('/api/v1/borrower/invoice')->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$mine->id], $ids->all());
    }

    #[Test]
    public function the_invoice_list_filters_by_status(): void
    {
        $this->invoiceFor($this->me);
        $paid = $this->invoiceFor($this->me, ['status' => Invoice::STATUS_PAID]);
        $this->actingAsUser($this->me);

        $ids = collect($this->postJson('/api/v1/borrower/invoice', ['status' => Invoice::STATUS_PAID])->assertOk()->json('data'))
            ->pluck('id');

        $this->assertSame([$paid->id], $ids->all());
    }

    #[Test]
    public function check_unpaid_lists_only_my_unpaid_invoices(): void
    {
        $unpaid = $this->invoiceFor($this->me);
        $this->invoiceFor($this->me, ['status' => Invoice::STATUS_PAID]);
        $this->invoiceFor($this->neighbour);
        $this->actingAsUser($this->me);

        $ids = collect($this->getJson('/api/v1/borrower/invoice/check-unpaid-invoice')->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$unpaid->id], $ids->all());
    }

    #[Test]
    public function i_can_open_my_invoice_by_id_or_by_invoice_number(): void
    {
        $invoice = $this->invoiceFor($this->me);
        $this->actingAsUser($this->me);

        $this->getJson("/api/v1/borrower/invoice/{$invoice->id}")->assertOk()->assertJsonPath('data.id', $invoice->id);
        $this->getJson("/api/v1/borrower/invoice/{$invoice->invoice_id}")->assertOk()->assertJsonPath('data.id', $invoice->id);
    }

    #[Test]
    public function another_borrowers_invoice_is_not_found_for_me(): void
    {
        $theirs = $this->invoiceFor($this->neighbour);
        $this->actingAsUser($this->me);

        $this->getJson("/api/v1/borrower/invoice/{$theirs->id}")->assertNotFound();
        $this->getJson("/api/v1/borrower/invoice/{$theirs->id}/download")->assertNotFound();
    }

    #[Test]
    public function downloading_my_invoice_returns_a_base64_pdf(): void
    {
        $invoice = $this->invoiceFor($this->me);
        $this->actingAsUser($this->me);

        $response = $this->getJson("/api/v1/borrower/invoice/{$invoice->id}/download")
            ->assertOk()
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.file_name', "Invoice-{$invoice->id}.pdf");

        $this->assertStringStartsWith('%PDF', base64_decode($response->json('data.file')));
    }

    #[Test]
    public function the_payment_list_holds_only_my_payments(): void
    {
        $mine = $this->paymentFor($this->me, $this->invoiceFor($this->me));
        $this->paymentFor($this->neighbour, $this->invoiceFor($this->neighbour));
        $this->actingAsUser($this->me);

        $ids = collect($this->postJson('/api/v1/borrower/payments/fetch-payments')->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$mine->id], $ids->all());
    }

    /** One of my payments, with the method, type and status given. */
    private function myPayment(array $attributes): Payment
    {
        $payment = $this->paymentFor($this->me, $this->invoiceFor($this->me));
        $payment->forceFill($attributes)->save();

        return $payment;
    }

    private function myPaymentIds(array $filters): array
    {
        $this->actingAsUser($this->me);

        return collect($this->postJson('/api/v1/borrower/payments/fetch-payments', $filters)->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
    }

    /** BUG-169: Method was sent but ignored - a Stripe payment stayed under "Square". */
    #[Test]
    public function the_payment_list_filters_by_method(): void
    {
        $stripe = $this->myPayment(['payment_from' => 'Stripe', 'payment_type' => 'Card']);
        $square = $this->myPayment(['payment_from' => 'Square', 'payment_type' => 'Card']);

        $this->assertSame([$square->id], $this->myPaymentIds(['payment_from' => 'Square']));
        $this->assertSame([$stripe->id], $this->myPaymentIds(['payment_from' => 'Stripe']));
        $this->assertSame([], $this->myPaymentIds(['payment_from' => 'PayPal']));
        $this->assertSame([$stripe->id, $square->id], $this->myPaymentIds(['payment_from' => null]));
    }

    /** Environment was sent but ignored - "Production" still listed sandbox payments. */
    #[Test]
    public function the_payment_list_filters_by_environment(): void
    {
        $live = $this->myPayment(['payment_from' => 'Stripe', 'is_environment' => true]);
        $sandbox = $this->myPayment(['payment_from' => 'Stripe', 'is_environment' => false]);

        $this->assertSame([$live->id], $this->myPaymentIds(['is_environment' => 1]));
        $this->assertSame([$sandbox->id], $this->myPaymentIds(['is_environment' => 0]));
        $this->assertSame([$live->id, $sandbox->id], $this->myPaymentIds(['is_environment' => null]));
    }

    /**
     * BUG-169: Type was sent but ignored. For a borrower a refund or a payout is
     * a Credit and every other payment a Debit - the same rule the DEBIT/CREDIT
     * column shows (Payment::getTransactionTypeForUser).
     */
    #[Test]
    public function the_payment_list_filters_by_type_as_the_table_shows_it(): void
    {
        $paid = $this->myPayment(['payment_from' => 'Stripe', 'payment_type' => 'Card', 'status' => Payment::STATUS_SUCCEEDED]);
        $noType = $this->myPayment(['payment_from' => 'Stripe', 'payment_type' => null, 'status' => Payment::STATUS_SUCCEEDED]);
        $refund = $this->myPayment(['payment_from' => 'Stripe', 'payment_type' => 'Card', 'status' => Payment::STATUS_REFUNDED]);
        $payout = $this->myPayment(['payment_from' => 'Stripe', 'payment_type' => Payment::TYPE_PAYOUT, 'status' => Payment::STATUS_SUCCEEDED]);

        $this->assertSame([$refund->id, $payout->id], $this->myPaymentIds(['transaction_type' => Payment::TRANSACTION_TYPE_CREDIT]));
        $this->assertSame([$paid->id, $noType->id], $this->myPaymentIds(['transaction_type' => Payment::TRANSACTION_TYPE_DEBIT]));

        $this->actingAsUser($this->me);
        $types = collect($this->postJson('/api/v1/borrower/payments/fetch-payments')->json('data'))->pluck('transaction_type', 'id');
        $this->assertSame(Payment::TRANSACTION_TYPE_CREDIT, $types[$refund->id]);
        $this->assertSame(Payment::TRANSACTION_TYPE_DEBIT, $types[$paid->id]);
    }

    #[Test]
    public function method_type_and_status_filters_apply_together(): void
    {
        $this->myPayment(['payment_from' => 'Stripe', 'payment_type' => 'Card', 'status' => Payment::STATUS_SUCCEEDED]);
        $match = $this->myPayment(['payment_from' => 'Square', 'payment_type' => 'Card', 'status' => Payment::STATUS_SUCCEEDED]);
        $this->myPayment(['payment_from' => 'Square', 'payment_type' => 'Card', 'status' => Payment::STATUS_REFUNDED]);

        $this->assertSame([$match->id], $this->myPaymentIds([
            'payment_from' => 'Square',
            'transaction_type' => Payment::TRANSACTION_TYPE_DEBIT,
            'status' => Payment::STATUS_SUCCEEDED,
        ]));
    }

    #[Test]
    public function the_payment_list_validates_its_filters(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/payments/fetch-payments', ['sort_order' => 'sideways'])->assertStatus(422);
        $this->postJson('/api/v1/borrower/payments/fetch-payments', ['per_page' => 0])->assertStatus(422);
        $this->postJson('/api/v1/borrower/payments/fetch-payments', ['date_from' => 'yesterday-ish'])->assertStatus(422);
    }

    #[Test]
    public function i_can_open_my_payment_but_not_someone_elses(): void
    {
        $mine = $this->paymentFor($this->me, $this->invoiceFor($this->me));
        $theirs = $this->paymentFor($this->neighbour, $this->invoiceFor($this->neighbour));
        $this->actingAsUser($this->me);

        $this->getJson("/api/v1/borrower/payments/{$mine->id}")->assertOk()->assertJsonPath('data.id', $mine->id);
        $this->getJson("/api/v1/borrower/payments/{$theirs->id}")->assertNotFound();
    }
}
