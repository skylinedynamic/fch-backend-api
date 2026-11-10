<?php

namespace Tests\Feature\Invoice;

use App\Models\Borrower\Borrower;
use App\Models\Invoice\Invoice;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Invoices list (host and partner portals):
 * - BUG-178: "Borrower Only" (is_mine=false) lists only invoices billed to a
 *   borrower - not a commission payout to a host user, which is neither.
 * - The Type filter narrows the list to one invoice type.
 */
class InvoicePayerAndTypeFilterTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $hostAdmin;
    private Partner $host;
    private Borrower $borrower;
    private Partner $partner;

    private Invoice $borrowerEnrollment;
    private Invoice $partnerEnrollment;
    private Invoice $partnerPaysBorrowerFee;
    /** A borrower fee recorded on the Borrower entity (legacy/E2E data) - still the partner's fee. */
    private Invoice $borrowerFeeOnBorrower;
    private Invoice $userPayout;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
        $this->borrower = $this->makeBorrower($this->host);
        $this->partner = $this->makeWhiteLabelPartner();
        $staff = $this->makeStaffUser($this->host);

        $this->borrowerEnrollment = $this->invoice(Borrower::class, $this->borrower->id, Invoice::INVOICE_TYPE_ENROLLMENT, 100);
        $this->partnerEnrollment = $this->invoice(Partner::class, $this->partner->id, Invoice::INVOICE_TYPE_ENROLLMENT, 200);
        // As EnrollmentService creates it: billed to the partner, partner pays.
        $this->partnerPaysBorrowerFee = $this->invoice(Partner::class, $this->partner->id, Invoice::INVOICE_TYPE_BORROWER_FEE, 300, true);
        $this->borrowerFeeOnBorrower = $this->invoice(Borrower::class, $this->borrower->id, Invoice::INVOICE_TYPE_BORROWER_FEE, 400);
        $this->userPayout = $this->invoice(User::class, $staff->id, Invoice::INVOICE_TYPE_COMMISSION_PAYOUT, 250);
    }

    private function invoice(string $entityType, int $entityId, string $type, int $amount, bool $partnerPaying = false): Invoice
    {
        $invoice = Invoice::create([
            'invoice_id' => 'INV-' . uniqid(),
            'partner_id' => $this->host->id,
            'borrower_id' => $entityType === Borrower::class ? $entityId : null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'customer_name' => 'Payee',
            'email' => 'payee@example.test',
            'payment_amount' => $amount,
            'currency' => 'USD',
            'status' => Invoice::STATUS_PAID,
            'type' => $type,
        ]);
        $invoice->forceFill(['is_partner_paying' => $partnerPaying])->save();

        return $invoice;
    }

    private function fetch(array $filters): array
    {
        $this->actingAsUser($this->hostAdmin);

        return $this->postJson('/api/v1/common/invoices/fetch-invoices', $filters + ['per_page' => 50])
            ->assertOk()
            ->json();
    }

    private function ids(array $filters): array
    {
        return collect($this->fetch($filters)['data'])->pluck('id')->sort()->values()->all();
    }

    private function sorted(Invoice ...$invoices): array
    {
        return collect($invoices)->pluck('id')->sort()->values()->all();
    }

    #[Test]
    public function borrower_only_leaves_out_a_commission_payout_to_a_host_user(): void
    {
        $this->assertSame($this->sorted($this->borrowerEnrollment), $this->ids(['is_mine' => false]));
    }

    #[Test]
    public function borrower_only_kpi_cards_leave_out_the_user_payout(): void
    {
        $this->assertSame('$100.00', $this->fetch(['is_mine' => false])['stats']['invoices_sent']);
    }

    #[Test]
    public function borrower_only_leaves_out_every_borrower_fee_the_partner_pays_it(): void
    {
        $ids = $this->ids(['is_mine' => false]);

        $this->assertNotContains($this->partnerPaysBorrowerFee->id, $ids);
        $this->assertNotContains($this->borrowerFeeOnBorrower->id, $ids);
    }

    #[Test]
    public function partner_only_lists_every_borrower_fee_and_all_lists_everything(): void
    {
        $this->assertSame(
            $this->sorted($this->partnerEnrollment, $this->partnerPaysBorrowerFee, $this->borrowerFeeOnBorrower),
            $this->ids(['is_mine' => true])
        );
        $this->assertSame(
            $this->sorted($this->borrowerEnrollment, $this->partnerEnrollment, $this->partnerPaysBorrowerFee, $this->borrowerFeeOnBorrower, $this->userPayout),
            $this->ids([])
        );
    }

    #[Test]
    public function the_type_filter_lists_only_that_invoice_type(): void
    {
        $this->assertSame($this->sorted($this->borrowerEnrollment, $this->partnerEnrollment), $this->ids(['type' => Invoice::INVOICE_TYPE_ENROLLMENT]));
        $this->assertSame($this->sorted($this->userPayout), $this->ids(['type' => Invoice::INVOICE_TYPE_COMMISSION_PAYOUT]));
        $this->assertSame([], $this->ids(['type' => Invoice::INVOICE_TYPE_USER_SEAT]));
        $this->assertSame(
            $this->sorted($this->partnerPaysBorrowerFee, $this->borrowerFeeOnBorrower),
            $this->ids(['type' => Invoice::INVOICE_TYPE_BORROWER_FEE])
        );
        $this->assertSame(5, count($this->ids(['type' => null])));
    }

    /** The Type column's own filter is typed as the table shows the type ("Borrower Fee"), not as stored. */
    #[Test]
    public function the_type_column_filter_matches_the_label_the_table_shows(): void
    {
        $column = fn (string $value) => ['filters' => ['type' => [
            'operator' => 'and',
            'constraints' => [['value' => $value, 'matchMode' => 'contains']],
        ]]];

        $this->assertSame(
            $this->sorted($this->partnerPaysBorrowerFee, $this->borrowerFeeOnBorrower),
            $this->ids($column('Borrower Fee'))
        );
        $this->assertSame($this->sorted($this->userPayout), $this->ids($column('commission pay')));
        $this->assertSame($this->sorted($this->borrowerEnrollment, $this->partnerEnrollment), $this->ids($column('enroll')));
    }

    #[Test]
    public function type_and_payer_filters_apply_together(): void
    {
        $this->assertSame(
            $this->sorted($this->partnerEnrollment),
            $this->ids(['type' => Invoice::INVOICE_TYPE_ENROLLMENT, 'is_mine' => true])
        );
    }

    #[Test]
    public function an_unknown_type_is_refused(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->postJson('/api/v1/common/invoices/fetch-invoices', ['type' => 'nonsense'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }
}
