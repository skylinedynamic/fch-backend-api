<?php

namespace Tests\Feature\Commission;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-107: commission /commissions/21 showed "Total Commission $4,000 (10%)"
 * on Requested $0 / Funded $0 with every borrower field blank. Deleting the
 * client soft-deleted its funding application but left the commission, whose
 * page then could not load the (trashed) application, while the list and the
 * Total Commission KPI still counted the $4,000.
 *
 * Now deleting a client takes its commissions that are not paid out with it,
 * and one already paid out keeps showing the deal it was paid on.
 */
class CommissionOfDeletedClientTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private User $hostOwner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        $this->host = $this->makeHostPartner();
        $this->hostOwner = $this->makePartnerAdmin($this->host);
    }

    private function deal(Borrower $borrower, string $paymentStatus): Commission
    {
        $application = FundingApplication::forceCreate([
            'borrower_id' => $borrower->id,
            'partner_id' => $this->host->id,
            'full_name' => 'Client ' . $borrower->id,
            'status' => FundingApplication::CLOSED_COMPLETED,
            'requested_amount' => 40000,
            'total_funded_amount' => 40000,
        ]);

        return Commission::create([
            'funding_application_id' => $application->id,
            'borrower_id' => $borrower->id,
            'partner_id' => $this->host->id,
            'total_commission' => 10,
            'admin_commission' => 100,
            'partner_commission' => 0,
            'total_commission_amount' => 4000,
            'admin_commission_amount' => 4000,
            'partner_commission_amount' => 0,
            'commission_status' => $paymentStatus === Commission::PAYMENT_STATUS_PAID ? Commission::STATUS_COMPLETED : Commission::STATUS_PROCESSING,
            'payment_status' => $paymentStatus,
        ]);
    }

    private function deleteClient(Borrower $borrower): void
    {
        $this->actingAsUser($this->hostOwner);
        $this->postJson('/api/v1/common/borrowers-multiple-delete', ['ids' => [$borrower->id]])->assertOk();
    }

    #[Test]
    public function deleting_a_client_removes_its_commissions_not_yet_paid_out(): void
    {
        $borrower = $this->makeBorrower($this->host);
        $unpaid = $this->deal($borrower, Commission::PAYMENT_STATUS_UNPAID);
        $pending = $this->deal($borrower, Commission::PAYMENT_STATUS_PENDING);

        $this->deleteClient($borrower);

        $this->assertSoftDeleted($unpaid);
        $this->assertSoftDeleted($pending);
        $listed = collect($this->postJson('/api/v1/common/commissions', ['per_page' => 50])->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($unpaid->id, $listed);
    }

    #[Test]
    public function a_commission_already_paid_out_stays_and_still_shows_its_deal(): void
    {
        $borrower = $this->makeBorrower($this->host);
        $paid = $this->deal($borrower, Commission::PAYMENT_STATUS_PAID);

        $this->deleteClient($borrower);

        $this->assertNotSoftDeleted($paid);
        $this->getJson("/api/v1/common/commissions/{$paid->id}")
            ->assertOk()
            ->assertJsonPath('data.funded_amount', fn ($v) => (float) $v === 40000.0)
            ->assertJsonPath('data.funding_requested_amount', fn ($v) => (float) $v === 40000.0)
            ->assertJsonPath('data.borrower_name', 'Client ' . $borrower->id);
    }

    /** The commissions already left behind by clients deleted before the fix. */
    #[Test]
    public function the_migration_clears_unpaid_commissions_left_on_deleted_funding_applications(): void
    {
        $borrower = $this->makeBorrower($this->host);
        $orphanUnpaid = $this->deal($borrower, Commission::PAYMENT_STATUS_UNPAID);
        $orphanPaid = $this->deal($borrower, Commission::PAYMENT_STATUS_PAID);
        FundingApplication::whereIn('id', [$orphanUnpaid->funding_application_id, $orphanPaid->funding_application_id])->delete();
        $live = $this->deal($this->makeBorrower($this->host), Commission::PAYMENT_STATUS_UNPAID);

        (require database_path('migrations/2026_10_27_120000_remove_unpaid_commissions_of_deleted_funding_applications.php'))->up();

        $this->assertSoftDeleted($orphanUnpaid);
        $this->assertNotSoftDeleted($orphanPaid);
        $this->assertNotSoftDeleted($live);
    }
}
