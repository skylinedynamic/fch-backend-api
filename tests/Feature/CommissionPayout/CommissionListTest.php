<?php

namespace Tests\Feature\CommissionPayout;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Commission\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-001: the owner's Commissions page showed $0 and "No commissions found"
 * while the Dashboard showed $31,600 of commission.
 *
 * The list resource read the funding application and partner without a null
 * check. A single commission whose client had been archived (soft-deleted)
 * threw, the endpoint answered 500, and the page lost every row and total.
 */
#[CoversClass(CommissionService::class)]
class CommissionListTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
            'company_name' => 'Host LLC',
            'full_name' => 'Host Owner',
            'email' => 'host@example.test',
        ]);

        $this->admin = User::create([
            'name' => 'Host Admin',
            'email' => 'admin@host.test',
            'password' => Hash::make('secret-password'),
            'status' => User::STATUS['active'],
            'user_type' => User::ADMIN,
            'partner_id' => $this->host->id,
            // The host's own login (its owner), who sees every commission.
            'entity_type' => Partner::class,
            'entity_id' => $this->host->id,
        ]);
    }

    private function commission(
        string $firstName,
        float $funded,
        string $status = Commission::STATUS_COMPLETED,
        string $paymentStatus = Commission::PAYMENT_STATUS_PAID,
    ): Commission {
        $borrower = Borrower::create([
            'partner_id' => $this->host->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => $firstName,
            'last_name' => 'Client',
            'email' => strtolower($firstName) . '@example.test',
        ]);

        $application = FundingApplication::forceCreate([
            'borrower_id' => $borrower->id,
            'full_name' => "{$firstName} Client",
            'status' => FundingApplication::CLOSED_COMPLETED,
            'total_funded_amount' => $funded,
        ]);

        return Commission::create([
            'funding_application_id' => $application->id,
            'borrower_id' => $borrower->id,
            'partner_id' => $this->host->id,
            'total_commission' => 10,
            'admin_commission' => 100,
            'partner_commission' => 0,
            'total_commission_amount' => $funded / 10,
            'admin_commission_amount' => $funded / 10,
            'partner_commission_amount' => 0,
            'commission_status' => $status,
            'payment_status' => $paymentStatus,
        ]);
    }

    /**
     * Defect: the cards were recounted from the filtered table, so filtering by
     * payment status or searching changed them; and "Total Deals" counted every
     * commission, not the completed ones.
     */
    #[Test]
    public function the_cards_keep_their_totals_while_filtered_and_count_only_completed_deals(): void
    {
        $this->commission('Done', 100000);
        $this->commission('Working', 200000, Commission::STATUS_PROCESSING, Commission::PAYMENT_STATUS_UNPAID);
        $this->commission('Waiting', 300000, Commission::STATUS_DRAFT, Commission::PAYMENT_STATUS_PENDING);

        Passport::actingAs($this->admin);

        $response = $this->postJson('/api/v1/common/commissions', [
            'page' => 1,
            'payment_status' => Commission::PAYMENT_STATUS_PAID,
        ])->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(60000, (float) $response->json('statistics.total_commission_amount'));
        $this->assertEquals(600000, (float) $response->json('statistics.total_funded_amount'));
        $this->assertSame(1, $response->json('statistics.completed_deals'));
    }

    #[Test]
    public function an_archived_clients_commission_does_not_empty_the_list(): void
    {
        $this->commission('Active', 100000);
        $archived = $this->commission('Archived', 200000);

        $archived->fundingApplication->delete();
        $archived->borrower->delete();

        Passport::actingAs($this->admin);

        $response = $this->postJson('/api/v1/common/commissions', ['page' => 1, 'per_page' => 10]);

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertContains('Archived Client', collect($response->json('data'))->pluck('borrower')->all());
        $this->assertEquals(30000, (float) $response->json('statistics.total_commission_amount'));
        $this->assertEquals(300000, (float) $response->json('statistics.total_funded_amount'));
    }

    #[Test]
    public function a_commission_with_no_funding_application_still_lists(): void
    {
        $commission = $this->commission('Orphan', 50000);
        $commission->fundingApplication()->getRelated()->newQuery()
            ->whereKey($commission->funding_application_id)->forceDelete();

        Passport::actingAs($this->admin);

        $response = $this->postJson('/api/v1/common/commissions', ['page' => 1]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertNull($response->json('data.0.borrower'));
    }
}
