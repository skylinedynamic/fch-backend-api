<?php

namespace Tests\Feature\Subscription;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\Subscription\Subscription;
use App\Models\User\User;
use App\Services\FCH\Subscription\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Defect (BUG-011, OP-SUB-04 / PP-SUB-05): sorting the Subscriptions list by
 * NAME or ENTITY TYPE showed error toasts and left the order unchanged.
 * subscriber_name is computed by the resource (partner's or borrower's name),
 * not a column, so ORDER BY subscriber_name failed in SQL.
 */
#[CoversClass(SubscriptionService::class)]
class SubscriptionListSortTest extends TestCase
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

        $this->subscribe(Partner::class, $this->wanda->id);
        foreach ([['Zia', 'Fuentes'], ['Kim', 'Saunders'], ['Orli', 'Hayes']] as [$first, $last]) {
            $borrower = $this->makeBorrower($this->wanda, ['first_name' => $first, 'last_name' => $last]);
            $this->subscribe(Borrower::class, $borrower->id);
        }
    }

    private function subscribe(string $entityType, int $entityId): Subscription
    {
        return Subscription::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'installment_count' => 1,
            'installments_paid' => 0,
            'payable_amount' => 300,
            'total_subscription_amount' => 300,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    private function column(User $viewer, string $sortField, string $order, string $key = 'subscriber_name'): array
    {
        $this->actingAsUser($viewer);

        return collect($this->postJson('/api/v1/common/subscriptions/fetch-all', [
            'page' => 1,
            'per_page' => 10,
            'sort_field' => $sortField,
            'sort_order' => $order,
        ])->assertOk()->json('data'))->pluck($key)->all();
    }

    #[Test]
    public function the_host_sorts_by_name_across_partners_and_clients(): void
    {
        $admin = $this->makePartnerAdmin($this->host);

        $this->assertSame(['Kim Saunders', 'Orli Hayes', 'Wanda Wells', 'Zia Fuentes'], $this->column($admin, 'subscriber_name', 'asc'));
        $this->assertSame(['Zia Fuentes', 'Wanda Wells', 'Orli Hayes', 'Kim Saunders'], $this->column($admin, 'subscriber_name', 'desc'));
    }

    #[Test]
    public function the_host_sorts_by_entity_type(): void
    {
        $admin = $this->makePartnerAdmin($this->host);

        $this->assertSame(['borrower', 'borrower', 'borrower', 'partner'], array_map('strtolower', $this->column($admin, 'entity_type', 'asc', 'entity_type')));
        $this->assertSame(['partner', 'borrower', 'borrower', 'borrower'], array_map('strtolower', $this->column($admin, 'entity_type', 'desc', 'entity_type')));
    }

    #[Test]
    public function a_white_label_partner_sorts_their_clients_by_name(): void
    {
        $wandaLogin = $this->makePartnerAdmin($this->wanda);

        $this->assertSame(['Kim Saunders', 'Orli Hayes', 'Zia Fuentes'], $this->column($wandaLogin, 'subscriber_name', 'asc'));
        $this->assertSame(['Zia Fuentes', 'Orli Hayes', 'Kim Saunders'], $this->column($wandaLogin, 'subscriber_name', 'desc'));
    }
}
