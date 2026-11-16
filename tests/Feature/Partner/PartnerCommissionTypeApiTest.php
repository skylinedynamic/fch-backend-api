<?php

namespace Tests\Feature\Partner;

use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdatePartnerCommissionRequest;
use App\Http\Resources\FCH\Common\Application\FundingApplicationResource;
use App\Http\Resources\FCH\Partner\PartnerDetailsResource;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\FundingApplication;
use App\Models\Partner\Partner;
use App\Models\Partner\PartnerCommissionTier;
use App\Models\User\User;
use App\Services\FCH\Partner\PartnerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Partner profile › Commissions: each tier has its own commission type -
 * Percentage (a share of the success fee, as before) or Flat Amount (a fixed
 * $ per deal) - so 0-10 can pay $500 a deal and 11+ pay 15%.
 */
#[CoversClass(UpdatePartnerCommissionRequest::class)]
#[CoversClass(PartnerService::class)]
#[CoversClass(PartnerDetailsResource::class)]
#[CoversClass(FundingApplicationResource::class)]
class PartnerCommissionTypeApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $partner;
    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->host = $this->makeHostPartner();
        $this->partner = $this->makeWhiteLabelPartner();
        $this->hostAdmin = $this->makeStaffUser($this->host);
    }

    private function url(int $partnerId): string
    {
        return "/api/v1/admin/partners/{$partnerId}/commission";
    }

    private static function flat(int $min, ?int $max, mixed $amount): array
    {
        return ['min_count' => $min, 'max_count' => $max, 'commission_type' => PartnerCommissionTier::TYPE_FLAT, 'amount' => $amount];
    }

    private static function rate(int $min, ?int $max, mixed $percentage): array
    {
        return ['min_count' => $min, 'max_count' => $max, 'commission_type' => PartnerCommissionTier::TYPE_PERCENTAGE, 'percentage' => $percentage];
    }

    private function storedTiers(Partner $partner): array
    {
        return PartnerCommissionTier::where('partner_id', $partner->id)->orderBy('sort_order')->get()
            ->map(fn ($t) => [
                $t->min_count,
                $t->max_count,
                $t->commission_type,
                $t->percentage === null ? null : (float) $t->percentage,
                $t->amount === null ? null : (float) $t->amount,
            ])->all();
    }

    #[Test]
    public function each_tier_has_its_own_type(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['tiers' => [
            self::flat(0, 10, 500),
            self::rate(11, 20, 12.5),
            self::flat(21, null, 750.5),
        ]])
            ->assertOk()
            ->assertJsonPath('data.commission_tiers.0.commission_type', 'flat')
            ->assertJsonPath('data.commission_tiers.0.amount', 500)
            ->assertJsonPath('data.commission_tiers.0.percentage', null)
            ->assertJsonPath('data.commission_tiers.1.commission_type', 'percentage')
            ->assertJsonPath('data.commission_tiers.1.percentage', 12.5)
            ->assertJsonPath('data.commission_tiers.1.amount', null)
            ->assertJsonPath('data.commission_tiers.2.amount', 750.5);

        $this->assertSame([
            [0, 10, 'flat', null, 500.0],
            [11, 20, 'percentage', 12.5, null],
            [21, null, 'flat', null, 750.5],
        ], $this->storedTiers($this->partner));
    }

    #[Test]
    public function only_the_tiers_own_types_value_is_kept(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['tiers' => [
            ['min_count' => 0, 'max_count' => 10, 'commission_type' => 'flat', 'amount' => 500, 'percentage' => 20],
            ['min_count' => 11, 'max_count' => null, 'commission_type' => 'percentage', 'amount' => 500, 'percentage' => 20],
        ]])->assertOk();

        $this->assertSame([[0, 10, 'flat', null, 500.0], [11, null, 'percentage', 20.0, null]], $this->storedTiers($this->partner));
    }

    #[Test]
    public function a_tier_with_no_type_is_a_percentage(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['tiers' => [['min_count' => 0, 'max_count' => null, 'percentage' => 15]]])
            ->assertOk()
            ->assertJsonPath('data.commission_tiers.0.commission_type', 'percentage');

        $this->assertSame([[0, null, 'percentage', 15.0, null]], $this->storedTiers($this->partner));
    }

    public static function invalidTiers(): array
    {
        return [
            'an unknown type' => [[['min_count' => 0, 'max_count' => null, 'commission_type' => 'bonus', 'amount' => 500]], 'tiers.0.commission_type'],
            'a flat tier without its amount' => [[self::rate(0, 10, 10), self::flat(11, null, null)], 'tiers.1.amount'],
            'only a percentage on a flat tier' => [[['min_count' => 0, 'max_count' => null, 'commission_type' => 'flat', 'percentage' => 20]], 'tiers.0.amount'],
            'only an amount on a percentage tier' => [[['min_count' => 0, 'max_count' => null, 'commission_type' => 'percentage', 'amount' => 500]], 'tiers.0.percentage'],
            'amount not a number' => [[self::flat(0, null, 'abc')], 'tiers.0.amount'],
            'negative amount' => [[self::flat(0, null, -1)], 'tiers.0.amount'],
            'amount too large' => [[self::flat(0, null, 100000000)], 'tiers.0.amount'],
            // The range checks apply whatever the types.
            'a gap' => [[self::flat(0, 10, 500), self::rate(15, null, 10)], 'tiers.1.min_count'],
        ];
    }

    #[Test]
    #[DataProvider('invalidTiers')]
    public function invalid_tiers_are_refused_under_their_field(array $tiers, string $errorKey): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['tiers' => $tiers])
            ->assertStatus(422)
            ->assertJsonValidationErrors([$errorKey]);

        $this->assertSame([], $this->storedTiers($this->partner));
    }

    #[Test]
    public function a_flat_tier_is_not_held_to_the_percentage_cap(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['tiers' => [self::flat(0, null, 5000)]])->assertOk();
    }

    /** The commission entries on the partner's activity log. */
    private function commissionLogs()
    {
        return ActivityLog::where('log_name', ActivityLog::LOG_TYPE_PARTNER_COMMISSION_UPDATE)
            ->where('subject_id', $this->partner->id)
            ->orderBy('id')
            ->get();
    }

    #[Test]
    public function flat_tiers_read_as_dollars_in_the_activity_log(): void
    {
        $this->hostAdmin->forceFill(['name' => 'Hana Host'])->save();
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['tiers' => [self::rate(0, null, 20)]])->assertOk();
        $this->putJson($this->url($this->partner->id), ['tiers' => [self::flat(0, 10, 1500), self::rate(11, null, 12.5)]])->assertOk();
        $this->putJson($this->url($this->partner->id), ['tiers' => [self::flat(0, null, 750.5)]])->assertOk();

        $logs = $this->commissionLogs();
        $this->assertCount(3, $logs);
        $this->assertSame('Hana Host changed the commission from 20% to 0-10: $1,500, 11+: 12.5%.', $logs[1]->description);
        $this->assertSame('$750.50', $logs[2]->properties['changes']['commission']['to']);
    }

    #[Test]
    public function switching_a_tiers_type_with_the_same_number_is_a_change(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['tiers' => [self::rate(0, null, 20)]])->assertOk();
        $this->putJson($this->url($this->partner->id), ['tiers' => [self::flat(0, null, 20)]])->assertOk();

        $logs = $this->commissionLogs();
        $this->assertCount(2, $logs);
        $this->assertSame('$20', $logs[1]->properties['changes']['commission']['to']);
    }

    private function giveTiers(array $tiers): void
    {
        foreach (array_values($tiers) as $i => $tier) {
            PartnerCommissionTier::create($tier + ['partner_id' => $this->partner->id, 'sort_order' => $i]);
        }
    }

    #[Test]
    public function the_partner_details_carry_each_tiers_type(): void
    {
        $this->giveTiers([
            ['min_count' => 0, 'max_count' => 10, 'commission_type' => 'flat', 'amount' => 400],
            ['min_count' => 11, 'max_count' => null, 'percentage' => 15],
        ]);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson("/api/v1/admin/partners-details/{$this->partner->id}")
            ->assertOk()
            ->assertJsonPath('data.commission_tiers.0.commission_type', 'flat')
            ->assertJsonPath('data.commission_tiers.0.amount', 400)
            // A tier stored before types existed is a percentage.
            ->assertJsonPath('data.commission_tiers.1.commission_type', 'percentage')
            ->assertJsonPath('data.commission_tiers.1.percentage', 15);
    }

    private function applicationJson(): array
    {
        $application = FundingApplication::forceCreate([
            'borrower_id' => $this->makeBorrower($this->partner)->id,
            'partner_id' => $this->partner->id,
            'status' => FundingApplication::IN_VERIFICATION,
            'requested_amount' => 100000,
        ]);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        return $this->getJson("/api/v1/common/request-application/funding-application/{$application->id}")
            ->assertOk()
            ->json('data');
    }

    #[Test]
    public function a_deal_in_a_flat_tier_has_no_percentage_default_but_the_modal_gets_its_amount(): void
    {
        $this->giveTiers([['min_count' => 0, 'max_count' => null, 'commission_type' => 'flat', 'amount' => 400]]);

        $data = $this->applicationJson();

        // The % depends on the success fee typed in the modal: the portal works it out.
        $this->assertNull($data['partner_default_commission']);
        $this->assertSame('flat', $data['partner_commission_setup']['tiers'][0]['commission_type']);
        $this->assertEquals(400, $data['partner_commission_setup']['tiers'][0]['amount']);
    }

    #[Test]
    public function a_deal_in_a_percentage_tier_still_opens_at_its_rate(): void
    {
        // Deal #1 is in the 0+ percentage tier.
        $this->giveTiers([['min_count' => 0, 'max_count' => null, 'commission_type' => 'percentage', 'percentage' => 25]]);

        $this->assertEquals(25, $this->applicationJson()['partner_default_commission']);
    }
}
