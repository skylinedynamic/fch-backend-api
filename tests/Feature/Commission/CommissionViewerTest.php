<?php

namespace Tests\Feature\Commission;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Compensation\CompensationSetting;
use App\Models\Partner\Partner;
use App\Models\PaymentMethod\PaymentProvider;
use App\Models\User\User;
use App\Services\FCH\Common\Commission\CommissionPayoutService;
use App\Services\FCH\Common\Commission\CommissionViewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * What each kind of portal user sees of commissions - on the Commissions list,
 * a commission's page and the dashboard card:
 *
 *   host owner     every deal, the host's share, and what was paid to its staff
 *   host staff     only their assigned clients' deals, and only their own payout
 *   partner owner  the partner's deals and its share - never the host's share
 *   partner staff  only their assigned clients' deals, no commission money
 *
 * Defect: host staff saw every deal and the host's whole share; partner staff
 * saw the partner's commission; partners received the host's share in the
 * API response (only the screen hid it).
 *
 * The deals:
 *   #1 host client of hostStaff, funded 100k at 10%  -> host 10,000, paid out (staff 20% = 2,000)
 *   #2 host client of hostStaff, funded 50k at 10%   -> host 5,000, unpaid (staff expects 1,000)
 *   #3 host client of another staff, not funded       -> host 2,000, pending
 *   #4 partner client of partnerStaff, 100k, 40% split -> host 6,000 / partner 4,000, paid
 *   #5 partner client unassigned, 30k, 50% split      -> host 1,500 / partner 1,500, unpaid
 */
#[CoversClass(CommissionViewer::class)]
class CommissionViewerTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private User $hostOwner;
    private User $hostStaff;
    private User $partnerOwner;
    private User $partnerStaff;
    /** @var array<int, Commission> */
    private array $deals = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->hostOwner = $this->makePartnerAdmin($this->host);
        $this->hostStaff = $this->makeStaffUser($this->host);
        $otherHostStaff = $this->makeStaffUser($this->host);
        $this->partnerOwner = $this->makePartnerAdmin($this->partner);
        $this->partnerStaff = $this->makeStaffUser($this->partner);

        // hostStaff is paid 20% of the host's share per funded deal.
        CompensationSetting::create([
            'partner_id' => $this->host->id,
            'entity_type' => \App\Models\Employee\Employee::class,
            'entity_id' => $this->hostStaff->entity_id,
            'paid_by' => CompensationSetting::PAID_BY_PER_DEAL,
            'service' => CommissionPayoutService::SERVICE_FUNDING,
            'payout_type' => CompensationSetting::PAYOUT_TYPE_COMMISSION,
            'amount' => 20,
        ]);

        $this->deals[1] = $this->deal($this->host, $this->hostStaff, 100000, funded: true, partnerRate: 0);
        $this->deals[2] = $this->deal($this->host, $this->hostStaff, 50000, funded: true, partnerRate: 0);
        $this->deals[3] = $this->deal($this->host, $otherHostStaff, 20000, funded: false, partnerRate: 0);
        $this->deals[4] = $this->deal($this->partner, $this->partnerStaff, 100000, funded: true, partnerRate: 40);
        $this->deals[5] = $this->deal($this->partner, null, 30000, funded: true, partnerRate: 50);

        // #1 is paid out to hostStaff the way the host pays it.
        Auth::setUser($this->hostOwner);
        app(CommissionPayoutService::class)->pay($this->deals[1], 'Bank Transfer', PaymentProvider::MODE_PRODUCTION);
        Auth::forgetUser();

        // #4 was paid to the partner.
        $this->deals[4]->update([
            'commission_status' => Commission::STATUS_COMPLETED,
            'payment_status' => Commission::PAYMENT_STATUS_PAID,
        ]);
    }

    private function deal(Partner $partner, ?User $assignee, float $amount, bool $funded, float $partnerRate): Commission
    {
        $borrower = $this->makeBorrower($partner, ['assign_id' => $assignee?->id]);

        $application = FundingApplication::forceCreate([
            'borrower_id' => $borrower->id,
            'partner_id' => $partner->id,
            'full_name' => 'Client ' . $borrower->id,
            'status' => $funded ? FundingApplication::CLOSED_COMPLETED : FundingApplication::SUBMITTED,
            'requested_amount' => $amount,
            'total_funded_amount' => $funded ? $amount : null,
        ]);

        $total = $amount * 10 / 100;
        $partnerShare = $total * $partnerRate / 100;

        return Commission::create([
            'funding_application_id' => $application->id,
            'borrower_id' => $borrower->id,
            'partner_id' => $partner->id,
            'total_commission' => 10,
            'admin_commission' => 100 - $partnerRate,
            'partner_commission' => $partnerRate,
            'total_commission_amount' => $total,
            'admin_commission_amount' => $total - $partnerShare,
            'partner_commission_amount' => $partnerShare,
            'commission_status' => $funded ? Commission::STATUS_PROCESSING : Commission::STATUS_DRAFT,
            'payment_status' => $funded ? Commission::PAYMENT_STATUS_UNPAID : Commission::PAYMENT_STATUS_PENDING,
        ]);
    }

    private function list(User $user): \Illuminate\Testing\TestResponse
    {
        $this->actingAsUser($user);

        return $this->postJson('/api/v1/common/commissions', ['per_page' => 50])->assertOk();
    }

    private function ids(\Illuminate\Testing\TestResponse $response): array
    {
        return collect($response->json('data'))->pluck('id')->sort()->values()->all();
    }

    private function dealIds(int ...$numbers): array
    {
        return collect($numbers)->map(fn ($n) => $this->deals[$n]->id)->sort()->values()->all();
    }

    private function dashboard(User $user): array
    {
        $this->actingAsUser($user);

        return $this->getJson('/api/v1/common/dashboard')->assertOk()->json('data');
    }

    // ---- Commissions list ------------------------------------------------------

    #[Test]
    public function the_host_owner_sees_every_deal_with_the_hosts_share_split_by_payment(): void
    {
        $response = $this->list($this->hostOwner);

        $this->assertSame($this->dealIds(1, 2, 3, 4, 5), $this->ids($response));
        $stats = $response->json('statistics');
        $this->assertSame(CommissionViewer::HOST_OWNER, $stats['viewer']);
        $this->assertEquals(24500, $stats['total_commission_amount']);
        $this->assertEquals(16000, $stats['paid_amount']);
        $this->assertEquals(2000, $stats['pending_amount']);
        $this->assertEquals(6500, $stats['unpaid_amount']);
        $this->assertEquals(2000, $stats['paid_to_staff']);
        $this->assertSame(2, $stats['completed_deals']);
    }

    /**
     * The host's cards also show the whole commission on its deals (host +
     * partners) and what it still has to pay out: the partner's share on
     * funded, unpaid partner deals (#5: 1,500) plus what its staff are owed
     * (#2: Sam 1,000). Deal #3 is not funded, so nothing is due on it yet.
     */
    #[Test]
    public function the_host_owner_sees_the_whole_commission_and_what_it_still_has_to_pay_out(): void
    {
        $stats = $this->list($this->hostOwner)->json('statistics');

        $this->assertEquals(30000, $stats['deal_commission_amount']);
        $this->assertEquals(2500, $stats['payouts_due_amount']);
    }

    #[Test]
    public function host_staff_see_only_their_clients_deals_and_their_own_payout(): void
    {
        $response = $this->list($this->hostStaff);

        $this->assertSame($this->dealIds(1, 2), $this->ids($response));
        $stats = $response->json('statistics');
        $this->assertSame(CommissionViewer::HOST_STAFF, $stats['viewer']);
        $this->assertEquals(2000, $stats['my_paid_amount']);
        $this->assertEquals(1000, $stats['my_expected_amount']);
        $this->assertSame(2, $stats['total_deals']);
        $this->assertArrayNotHasKey('total_commission_amount', $stats);
        $this->assertArrayNotHasKey('paid_to_staff', $stats);

        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertEquals(2000, $rows[$this->deals[1]->id]['my_payout']);
        $this->assertEquals(1000, $rows[$this->deals[2]->id]['my_payout']);
        $this->assertArrayNotHasKey('admin_commission_amount', $rows->first());
        $this->assertArrayNotHasKey('total_commission_amount', $rows->first());
    }

    #[Test]
    public function the_partner_owner_sees_its_share_and_never_the_hosts(): void
    {
        $response = $this->list($this->partnerOwner);

        $this->assertSame($this->dealIds(4, 5), $this->ids($response));
        $stats = $response->json('statistics');
        $this->assertSame(CommissionViewer::PARTNER_OWNER, $stats['viewer']);
        $this->assertEquals(5500, $stats['total_commission_amount']);
        $this->assertEquals(4000, $stats['paid_amount']);
        $this->assertEquals(0, $stats['pending_amount']);
        $this->assertEquals(1500, $stats['unpaid_amount']);
        $this->assertArrayNotHasKey('paid_to_staff', $stats);
        $this->assertArrayNotHasKey('partner_commission_amount', $stats);

        $row = $response->json('data.0');
        $this->assertArrayHasKey('partner_commission_amount', $row);
        $this->assertArrayNotHasKey('admin_commission_amount', $row);
        $this->assertArrayNotHasKey('admin_commission', $row);
    }

    #[Test]
    public function partner_staff_see_their_clients_deals_without_any_commission_money(): void
    {
        $response = $this->list($this->partnerStaff);

        $this->assertSame($this->dealIds(4), $this->ids($response));
        $stats = $response->json('statistics');
        $this->assertSame(CommissionViewer::PARTNER_STAFF, $stats['viewer']);
        $this->assertSame(1, $stats['total_deals']);
        $this->assertSame(1, $stats['completed_deals']);
        foreach (['total_commission_amount', 'paid_amount', 'unpaid_amount', 'avg_commission_rate'] as $money) {
            $this->assertArrayNotHasKey($money, $stats);
        }

        $row = $response->json('data.0');
        foreach (['total_commission_amount', 'admin_commission_amount', 'partner_commission_amount', 'partner_commission'] as $money) {
            $this->assertArrayNotHasKey($money, $row);
        }
    }

    /**
     * A partner sees a deal's commission only once the host closes its
     * application as Closed Completed. Before that the host may still change
     * the rates, so the figure the partner would see is not final.
     */
    #[Test]
    public function partners_see_a_commission_only_once_its_application_is_closed_completed(): void
    {
        $draft = $this->deal($this->partner, $this->partnerStaff, 80000, funded: false, partnerRate: 50);
        $before = $this->list($this->partnerOwner)->json('statistics');

        foreach ([$this->partnerOwner, $this->partnerStaff] as $partnerUser) {
            $this->assertNotContains($draft->id, $this->ids($this->list($partnerUser)));
            $this->getJson("/api/v1/common/commissions/{$draft->id}")->assertNotFound();
        }
        $this->assertSame($this->dealIds(4, 5), $this->ids($this->list($this->partnerOwner)));
        $this->assertEquals(5500, $before['total_commission_amount'], 'the unfunded deal was counted in the partner\'s totals');
        $this->assertEquals(0, $before['pending_amount']);
        $this->assertSame(2, $before['total_deals']);

        // The host still sees it, to set it up.
        $this->assertContains($draft->id, $this->ids($this->list($this->hostOwner)));

        // Once the application is Closed Completed the partner sees it.
        $draft->fundingApplication->forceFill([
            'status' => FundingApplication::CLOSED_COMPLETED,
            'total_funded_amount' => 80000,
        ])->save();
        $this->assertContains($draft->id, $this->ids($this->list($this->partnerOwner)));
        $this->assertContains($draft->id, $this->ids($this->list($this->partnerStaff)));
    }

    #[Test]
    public function a_partners_dashboard_card_leaves_out_commissions_not_closed_completed(): void
    {
        $before = $this->dashboard($this->partnerOwner);
        $this->deal($this->partner, $this->partnerStaff, 80000, funded: false, partnerRate: 50);
        $after = $this->dashboard($this->partnerOwner);

        // The new client and application count elsewhere on the dashboard; the commission card must not move.
        $this->assertEquals($before['commission'], $after['commission']);
        $this->assertEquals($before['counts']['total_commission'], $after['counts']['total_commission']);
    }

    /**
     * Host staff allowed to manage commissions (they pay them out) need the
     * host's whole book, not just their own clients.
     */
    #[Test]
    public function host_staff_who_manage_commissions_see_the_hosts_whole_book(): void
    {
        $manager = $this->makeStaffUser($this->host, ['update commission']);

        $response = $this->list($manager);

        $this->assertSame($this->dealIds(1, 2, 3, 4, 5), $this->ids($response));
        $this->assertSame(CommissionViewer::HOST_OWNER, $response->json('statistics.viewer'));
        $this->assertEquals(16000, $response->json('statistics.paid_amount'));
    }

    // ---- A commission's page ---------------------------------------------------

    #[Test]
    public function staff_cannot_open_a_deal_that_is_not_their_clients(): void
    {
        $this->actingAsUser($this->hostStaff);
        $this->getJson("/api/v1/common/commissions/{$this->deals[3]->id}")->assertNotFound();
        $this->getJson("/api/v1/common/commissions/{$this->deals[4]->id}")->assertNotFound();

        $this->actingAsUser($this->partnerStaff);
        $this->getJson("/api/v1/common/commissions/{$this->deals[5]->id}")->assertNotFound();
    }

    #[Test]
    public function each_viewer_gets_only_their_own_money_on_a_commissions_page(): void
    {
        $this->actingAsUser($this->hostStaff);
        $mine = $this->getJson("/api/v1/common/commissions/{$this->deals[1]->id}")->assertOk()->json('data');
        $this->assertSame(CommissionViewer::HOST_STAFF, $mine['viewer']);
        $this->assertEquals(2000, $mine['payout']['amount']);
        $this->assertArrayNotHasKey('admin_commission_amount', $mine);
        $this->assertArrayNotHasKey('total_commission_amount', $mine);

        $this->actingAsUser($this->partnerOwner);
        $theirs = $this->getJson("/api/v1/common/commissions/{$this->deals[4]->id}")->assertOk()->json('data');
        $this->assertEquals(4000, $theirs['partner_commission_amount']);
        $this->assertArrayNotHasKey('admin_commission_amount', $theirs);

        $this->actingAsUser($this->partnerStaff);
        $deal = $this->getJson("/api/v1/common/commissions/{$this->deals[4]->id}")->assertOk()->json('data');
        $this->assertSame(CommissionViewer::PARTNER_STAFF, $deal['viewer']);
        foreach (['total_commission_amount', 'admin_commission_amount', 'partner_commission_amount', 'payout'] as $money) {
            $this->assertArrayNotHasKey($money, $deal);
        }
    }

    /**
     * Defect: the payout endpoint only required an Admin-type login and the
     * Pay button only checked can_pay, so host staff could pay out their own
     * commission. Only the host owner, or staff who manage commissions, pay.
     */
    #[Test]
    public function host_staff_cannot_pay_out_their_own_commission(): void
    {
        $this->actingAsUser($this->hostStaff);

        $this->assertFalse($this->getJson("/api/v1/common/commissions/{$this->deals[2]->id}")->assertOk()->json('data.payout.can_pay'));
        $this->postJson("/api/v1/admin/commission/{$this->deals[2]->id}/payout", ['partner_payment_method' => 'Bank Transfer', 'environment' => PaymentProvider::MODE_PRODUCTION])
            ->assertForbidden();
        $this->assertSame(Commission::PAYMENT_STATUS_UNPAID, $this->deals[2]->fresh()->payment_status);

        $manager = $this->makeStaffUser($this->host, ['update commission']);
        $this->actingAsUser($manager);
        $this->assertTrue($this->getJson("/api/v1/common/commissions/{$this->deals[2]->id}")->assertOk()->json('data.payout.can_pay'));
        $this->postJson("/api/v1/admin/commission/{$this->deals[2]->id}/payout", ['partner_payment_method' => 'Bank Transfer', 'environment' => PaymentProvider::MODE_PRODUCTION])
            ->assertOk();
    }

    // ---- Dashboard card --------------------------------------------------------

    /**
     * The card's headline is what was earned: commission on funded deals,
     * paid out or not (Paid + Unpaid; for host staff, paid + expected).
     * Deal #3 is not funded, so its pending commission is not earned yet.
     */
    #[Test]
    public function the_dashboard_card_shows_what_each_viewer_has_earned(): void
    {
        $this->assertEquals(22500, $this->dashboard($this->hostOwner)['commission']['earned_amount']);
        $this->assertEquals(3000, $this->dashboard($this->hostStaff)['commission']['earned_amount']);
        $this->assertEquals(5500, $this->dashboard($this->partnerOwner)['commission']['earned_amount']);
        $this->assertArrayNotHasKey('earned_amount', $this->dashboard($this->partnerStaff)['commission']);
    }

    #[Test]
    public function the_dashboard_commission_card_follows_the_viewer(): void
    {
        $host = $this->dashboard($this->hostOwner);
        $this->assertSame(CommissionViewer::HOST_OWNER, $host['commission']['viewer']);
        $this->assertEquals(16000, $host['commission']['paid_amount']);
        $this->assertEquals(2000, $host['commission']['pending_amount']);
        $this->assertEquals(6500, $host['commission']['unpaid_amount']);
        $this->assertEquals(2000, $host['commission']['paid_to_staff']);
        $this->assertEquals(16000, $host['counts']['total_commission']);

        $staff = $this->dashboard($this->hostStaff);
        $this->assertSame(CommissionViewer::HOST_STAFF, $staff['commission']['viewer']);
        $this->assertEquals(2000, $staff['commission']['paid_amount']);
        $this->assertEquals(1000, $staff['commission']['expected_amount']);
        $this->assertSame(1, $staff['commission']['expected_deals']);
        $this->assertEquals(2000, $staff['counts']['total_commission']);

        $partner = $this->dashboard($this->partnerOwner);
        $this->assertSame(CommissionViewer::PARTNER_OWNER, $partner['commission']['viewer']);
        $this->assertEquals(4000, $partner['commission']['paid_amount']);
        $this->assertEquals(1500, $partner['commission']['unpaid_amount']);
        $this->assertEquals(4000, $partner['counts']['total_commission']);

        $partnerStaff = $this->dashboard($this->partnerStaff);
        $this->assertSame(CommissionViewer::PARTNER_STAFF, $partnerStaff['commission']['viewer']);
        $this->assertSame(1, $partnerStaff['commission']['deals']);
        $this->assertSame(1, $partnerStaff['commission']['completed_deals']);
        $this->assertArrayNotHasKey('paid_amount', $partnerStaff['commission']);
        $this->assertArrayNotHasKey('total_commission', $partnerStaff['counts']);
    }
}
