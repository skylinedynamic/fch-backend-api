<?php

namespace Tests\Feature\Billing;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Services\FCH\Common\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Defect (BUG-009, PP-BILL-05): sorting Upcoming Billings by NAME did not
 * change the order. The sort read the partner's name only, but a client's row
 * shows the client's own name - on a white-label partner's list every row is a
 * client, so every row sorted on NULL. The join it used also let the partner's
 * id and status overwrite the subscription's own on each row.
 */
#[CoversClass(BillingService::class)]
class UpcomingBillingSortTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Partner $host;

    private Partner $wanda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->wanda = $this->makeWhiteLabelPartner(['full_name' => 'Wanda Wells']);
    }

    private function subscribe(string $entityType, int $entityId): Subscription
    {
        return Subscription::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'installment_count' => 3,
            'installments_paid' => 1,
            'payable_amount' => 300,
            'total_subscription_amount' => 900,
            'subscription_amount_per_month' => 300,
            'current_phase' => Subscription::PHASE_INSTALLMENT,
            'subscription_end_date' => now()->addDays(3)->toDateString(),
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    private function clientOf(Partner $partner, string $first, string $last): Subscription
    {
        return $this->subscribe(Borrower::class, $this->makeBorrower($partner, ['first_name' => $first, 'last_name' => $last])->id);
    }

    private function rows(User $viewer, string $order): array
    {
        $this->actingAsUser($viewer);

        return $this->postJson('/api/v1/common/billing/fetch-upcoming', [
            'page' => 1,
            'per_page' => 10,
            'sort_field' => 'partner_name',
            'sort_order' => $order,
        ])->assertOk()->json('data');
    }

    #[Test]
    public function a_white_label_partner_sorts_their_clients_by_name(): void
    {
        $this->clientOf($this->wanda, 'Zia', 'Fuentes');
        $this->clientOf($this->wanda, 'Kim', 'Saunders');
        $wandaLogin = $this->makePartnerAdmin($this->wanda);

        $this->assertSame(['Kim Saunders', 'Zia Fuentes'], array_column($this->rows($wandaLogin, 'asc'), 'subscriber_name'));
        $this->assertSame(['Zia Fuentes', 'Kim Saunders'], array_column($this->rows($wandaLogin, 'desc'), 'subscriber_name'));
    }

    #[Test]
    public function the_host_sorts_partners_and_its_own_clients_together(): void
    {
        $this->subscribe(Partner::class, $this->wanda->id);
        $this->clientOf($this->host, 'Zia', 'Fuentes');
        $this->clientOf($this->host, 'Kim', 'Saunders');
        $admin = $this->makePartnerAdmin($this->host);

        $this->assertSame(['Kim Saunders', 'Wanda Wells', 'Zia Fuentes'], array_column($this->rows($admin, 'asc'), 'subscriber_name'));
        $this->assertSame(['Zia Fuentes', 'Wanda Wells', 'Kim Saunders'], array_column($this->rows($admin, 'desc'), 'subscriber_name'));
    }

    #[Test]
    public function sorting_by_name_keeps_each_rows_own_subscription_id_and_status(): void
    {
        $subscription = $this->subscribe(Partner::class, $this->wanda->id);
        $this->wanda->update(['status' => Partner::STATUS_SUSPENDED]);
        $admin = $this->makePartnerAdmin($this->host);

        $row = $this->rows($admin, 'asc')[0];

        $this->assertSame($subscription->id, $row['subscription_id']);
        $this->assertSame(Subscription::STATUS_ACTIVE, $row['status']);
    }
}
