<?php

namespace Tests\Feature\Payment;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\Payment\Payment;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-179: the Payments list NAME column sorts by the payer name it shows -
 * the partner's full name, the borrower's first + last name, or the host user
 * a commission was paid to - not by date.
 */
class PaymentListNameSortTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $hostAdmin;
    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
    }

    private function payment(string $entityType, int $entityId, string $date, array $extra = []): Payment
    {
        return Payment::create(array_merge([
            'partner_id' => $this->host->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'transaction_id' => 'TX-' . uniqid(),
            'amount' => 10,
            'currency' => 'USD',
            'status' => Payment::STATUS_SUCCEEDED,
            'transaction_date' => $date,
        ], $extra));
    }

    private function names(array $filters): array
    {
        $this->actingAsUser($this->hostAdmin);

        return collect($this->postJson('/api/v1/common/payments/fetch-payments', $filters + ['per_page' => 50])
            ->assertOk()->json('data'))
            ->map(fn ($row) => $row['partner_name'] ?: ($row['borrower_name'] ?: $row['name']))
            ->all();
    }

    #[Test]
    public function the_name_column_sorts_by_the_payer_name_shown(): void
    {
        // Dates run opposite to the names, so a date sort cannot pass for a name sort.
        $zed = $this->makeWhiteLabelPartner(['full_name' => 'Zed Partner']);
        $this->payment(Partner::class, $zed->id, '2026-01-01', ['partner_id' => $zed->id]);

        $mia = $this->makeBorrower($this->host, ['first_name' => 'Mia', 'last_name' => 'Borrower']);
        $this->payment(Borrower::class, $mia->id, '2026-02-01', ['borrower_id' => $mia->id]);

        $staff = $this->makeStaffUser($this->host, [], ['name' => 'Kim Staff']);
        $this->payment(User::class, $staff->id, '2026-03-01');

        $abe = $this->makeBorrower($this->host, ['first_name' => 'Abe', 'last_name' => 'Borrower']);
        $this->payment(Borrower::class, $abe->id, '2026-04-01', ['borrower_id' => $abe->id]);

        $this->assertSame(
            ['Abe Borrower', 'Kim Staff', 'Mia Borrower', 'Zed Partner'],
            $this->names(['sort_field' => 'name', 'sort_order' => 'asc'])
        );
        $this->assertSame(
            ['Zed Partner', 'Mia Borrower', 'Kim Staff', 'Abe Borrower'],
            $this->names(['sort_field' => 'name', 'sort_order' => 'desc'])
        );
    }
}
