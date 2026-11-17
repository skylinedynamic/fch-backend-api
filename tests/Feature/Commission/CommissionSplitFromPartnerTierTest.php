<?php

namespace Tests\Feature\Commission;

use App\Http\Requests\Api\V1\FCH\Admin\Commission\SaveCommissionRequest;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Partner\Partner;
use App\Models\Partner\PartnerCommissionTier;
use App\Models\User\User;
use App\Services\FCH\Admin\Commission\CommissionService;
use App\Services\FCH\Common\Commission\CommissionSplitCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Closed (Completed): a partner client's admin / partner split comes from the
 * partner's commission tier for that deal (Partner profile › Commissions) and
 * can't be changed by the client. A Flat Amount tier pays exactly that $ of
 * the success fee - kept as the amount, not a rounded %, so closing at the
 * final funded amount still pays exactly it.
 */
#[CoversClass(CommissionService::class)]
#[CoversClass(SaveCommissionRequest::class)]
#[CoversClass(CommissionSplitCalculator::class)]
class CommissionSplitFromPartnerTierTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        $this->hostAdmin = $this->makeStaffUser($this->makeHostPartner());
        $this->partner = $this->makeWhiteLabelPartner();
    }

    private function tier(array $attributes): void
    {
        PartnerCommissionTier::create($attributes + ['partner_id' => $this->partner->id, 'min_count' => 0, 'max_count' => null, 'sort_order' => 0]);
    }

    private function application(Partner $partner, array $attributes = []): FundingApplication
    {
        return FundingApplication::forceCreate(array_merge([
            'borrower_id' => $this->makeBorrower($partner)->id,
            'partner_id' => $partner->id,
            'status' => FundingApplication::SUBMITTED,
            'requested_amount' => 250000,
        ], $attributes));
    }

    private function save(FundingApplication $application, array $split): void
    {
        $this->actingAsUser($this->hostAdmin);
        $this->postJson('/api/v1/admin/funding-application/save-commission', ['funding_application_id' => $application->id, 'total_commission' => 12] + $split)
            ->assertOk();
    }

    private function commissionOf(FundingApplication $application): Commission
    {
        return Commission::where('funding_application_id', $application->id)->firstOrFail();
    }

    #[Test]
    public function a_flat_tier_pays_exactly_its_amount(): void
    {
        $this->tier(['commission_type' => PartnerCommissionTier::TYPE_FLAT, 'amount' => 500]);
        $application = $this->application($this->partner);

        // $250,000 x 12% = $30,000. The portal's rounded 1.67% would be $501.
        $this->save($application, ['admin_commission' => 98.33, 'partner_commission' => 1.67]);

        $commission = $this->commissionOf($application);
        $this->assertEquals(30000, $commission->total_commission_amount);
        $this->assertEquals(500, $commission->partner_commission_amount);
        $this->assertEquals(29500, $commission->admin_commission_amount);
        $this->assertSame(PartnerCommissionTier::TYPE_FLAT, $commission->partner_commission_type);
        $this->assertEquals(500, $commission->partner_commission_flat_amount);
        $this->assertEquals(1.67, $commission->partner_commission);
        $this->assertEquals(98.33, $commission->admin_commission);
    }

    #[Test]
    public function a_percentage_tier_sets_the_split_whatever_the_client_sends(): void
    {
        $this->tier(['commission_type' => PartnerCommissionTier::TYPE_PERCENTAGE, 'percentage' => 20]);
        $application = $this->application($this->partner);

        $this->save($application, ['admin_commission' => 40, 'partner_commission' => 60]);

        $commission = $this->commissionOf($application);
        $this->assertEquals(20, $commission->partner_commission);
        $this->assertEquals(80, $commission->admin_commission);
        $this->assertEquals(6000, $commission->partner_commission_amount);
        $this->assertEquals(24000, $commission->admin_commission_amount);
        $this->assertSame(PartnerCommissionTier::TYPE_PERCENTAGE, $commission->partner_commission_type);
        $this->assertNull($commission->partner_commission_flat_amount);
    }

    #[Test]
    public function with_no_tier_for_the_deal_the_admin_keeps_it_all_whatever_is_sent(): void
    {
        $application = $this->application($this->partner);

        // The split can't be typed: with no commission set the partner gets none.
        $this->save($application, ['admin_commission' => 40, 'partner_commission' => 60]);

        $commission = $this->commissionOf($application);
        $this->assertEquals(0, $commission->partner_commission);
        $this->assertEquals(100, $commission->admin_commission);
        $this->assertEquals(0, $commission->partner_commission_amount);
        $this->assertEquals(30000, $commission->admin_commission_amount);
        $this->assertNull($commission->partner_commission_type);
    }

    #[Test]
    public function the_admin_keeps_at_least_1_percent_when_the_flat_amount_is_more_than_the_fee(): void
    {
        $this->tier(['commission_type' => PartnerCommissionTier::TYPE_FLAT, 'amount' => 50000]);
        $application = $this->application($this->partner);

        $this->save($application, ['admin_commission' => 1, 'partner_commission' => 99]);

        $commission = $this->commissionOf($application);
        $this->assertEquals(29700, $commission->partner_commission_amount);
        $this->assertEquals(300, $commission->admin_commission_amount);
        $this->assertEquals(99, $commission->partner_commission);
    }

    #[Test]
    public function closing_at_the_final_funded_amount_still_pays_exactly_the_flat_amount(): void
    {
        $this->tier(['commission_type' => PartnerCommissionTier::TYPE_FLAT, 'amount' => 500]);
        $application = $this->application($this->partner);
        $lenderId = DB::table('lenders')->insertGetId(['name' => 'Fundbox Financing', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $application->forceFill(['lender_ids' => [$lenderId], 'assign_lender' => $lenderId])->save();
        $this->save($application, ['admin_commission' => 98.33, 'partner_commission' => 1.67]);

        // Closed at $100,000: a $12,000 success fee.
        $this->postJson('/api/v1/common/request-application/funding-application/status-update', [
            'funding_application_id' => $application->id,
            'status' => FundingApplication::CLOSED_COMPLETED,
            'funded_amount' => 100000,
        ])->assertOk();

        $commission = $this->commissionOf($application);
        $this->assertEquals(12000, $commission->total_commission_amount);
        $this->assertEquals(500, $commission->partner_commission_amount);
        $this->assertEquals(11500, $commission->admin_commission_amount);
        $this->assertEquals(4.17, $commission->partner_commission);
    }

    #[Test]
    public function a_host_client_has_no_partner_share(): void
    {
        $host = Partner::where('is_host', true)->firstOrFail();
        PartnerCommissionTier::create(['partner_id' => $host->id, 'min_count' => 0, 'commission_type' => 'flat', 'amount' => 500, 'sort_order' => 0]);
        $application = $this->application($host);

        $this->save($application, ['admin_commission' => 12]);

        $commission = $this->commissionOf($application);
        $this->assertEquals(0, $commission->partner_commission_amount);
        $this->assertEquals(30000, $commission->admin_commission_amount);
    }

    /**
     * Commission details: a flat share is a fixed $ - its type, and the
     * partner payout as a Flat Amount, not "x% of the total commission".
     */
    #[Test]
    public function the_commission_details_show_a_flat_share_as_a_flat_amount(): void
    {
        $this->tier(['commission_type' => PartnerCommissionTier::TYPE_FLAT, 'amount' => 500]);
        $flat = $this->application($this->partner, ['status' => FundingApplication::CLOSED_COMPLETED, 'total_funded_amount' => 250000]);
        $this->save($flat, ['admin_commission' => 98.33, 'partner_commission' => 1.67]);
        $commission = $this->commissionOf($flat);

        $this->actingAsUser($this->makePartnerAdmin(Partner::where('is_host', true)->firstOrFail()));
        $data = $this->getJson("/api/v1/common/commissions/{$commission->id}")->assertOk()->json('data');

        $this->assertSame(PartnerCommissionTier::TYPE_FLAT, $data['partner_commission_type']);
        $this->assertSame(\App\Models\Compensation\CompensationSetting::PAYOUT_TYPE_FLAT_AMOUNT, $data['payout']['payout_type']);
        $this->assertNull($data['payout']['rate']);
        $this->assertEquals(500, $data['payout']['amount']);
    }

    #[Test]
    public function a_percentage_share_is_still_a_rate_in_the_commission_details(): void
    {
        $this->tier(['commission_type' => PartnerCommissionTier::TYPE_PERCENTAGE, 'percentage' => 20]);
        $application = $this->application($this->partner, ['status' => FundingApplication::CLOSED_COMPLETED, 'total_funded_amount' => 250000]);
        $this->save($application, ['admin_commission' => 80, 'partner_commission' => 20]);
        $commission = $this->commissionOf($application);

        $this->actingAsUser($this->makePartnerAdmin(Partner::where('is_host', true)->firstOrFail()));
        $data = $this->getJson("/api/v1/common/commissions/{$commission->id}")->assertOk()->json('data');

        $this->assertSame(PartnerCommissionTier::TYPE_PERCENTAGE, $data['partner_commission_type']);
        $this->assertSame(\App\Models\Compensation\CompensationSetting::PAYOUT_TYPE_COMMISSION, $data['payout']['payout_type']);
        $this->assertEquals(20, $data['payout']['rate']);
    }

    /** The details page's Closed summary shows a flat share in $, not as a %. */
    #[Test]
    public function the_details_say_how_the_partner_share_was_set(): void
    {
        $this->tier(['commission_type' => PartnerCommissionTier::TYPE_FLAT, 'amount' => 500]);
        $flat = $this->application($this->partner);
        $this->save($flat, ['admin_commission' => 98.33, 'partner_commission' => 1.67]);
        $none = $this->application($this->makeWhiteLabelPartner());
        $this->save($none, ['admin_commission' => 40, 'partner_commission' => 60]);

        $detail = fn (FundingApplication $application) => $this->getJson("/api/v1/common/request-application/funding-application/{$application->id}")
            ->assertOk()
            ->json('data.partner_commission_type');

        $this->assertSame(PartnerCommissionTier::TYPE_FLAT, $detail($flat));
        $this->assertNull($detail($none));
    }
}
