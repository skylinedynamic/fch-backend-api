<?php

namespace Tests\Feature\Partner;

use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerController;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdatePartnerCommissionRequest;
use App\Http\Resources\FCH\Common\Application\FundingApplicationResource;
use App\Http\Resources\FCH\Partner\PartnerDetailsResource;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\FundingApplication;
use App\Models\Partner\Partner;
use App\Models\Partner\PartnerCommissionTier;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Partner profile › Commissions: the host sets a partner's Funding commission
 * tiers - the partner's share (%) of the success fee, by how many of its
 * clients' funding applications have been funded (0-10, 11-20, ...). The
 * funding application carries the tier for that deal so the split opens at it.
 */
#[CoversClass(PartnerController::class)]
#[CoversClass(UpdatePartnerCommissionRequest::class)]
#[CoversClass(PartnerDetailsResource::class)]
#[CoversClass(FundingApplicationResource::class)]
class PartnerCommissionApiTest extends TestCase
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

    private static function tier(int $min, ?int $max, float $percentage): array
    {
        return ['min_count' => $min, 'max_count' => $max, 'percentage' => $percentage];
    }

    /** Store tiers directly, as a starting point. */
    private function giveTiers(Partner $partner, array $tiers): void
    {
        foreach (array_values($tiers) as $i => $tier) {
            PartnerCommissionTier::create($tier + ['partner_id' => $partner->id, 'sort_order' => $i]);
        }
    }

    private function storedTiers(Partner $partner): array
    {
        return PartnerCommissionTier::where('partner_id', $partner->id)->orderBy('sort_order')->get()
            ->map(fn ($t) => [$t->min_count, $t->max_count, (float) $t->percentage])->all();
    }

    #[Test]
    public function the_host_sets_a_partners_funding_tiers(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['tiers' => [
            self::tier(0, 10, 10),
            self::tier(11, 20, 12.5),
            self::tier(21, null, 15),
        ]])
            ->assertOk()
            ->assertJsonPath('data.id', $this->partner->id)
            ->assertJsonPath('data.commission_tiers.1.min_count', 11)
            ->assertJsonPath('data.commission_tiers.1.percentage', 12.5)
            ->assertJsonPath('data.commission_tiers.2.max_count', null)
            ->assertJsonPath('data.funded_deal_count', 0)
            ->assertJsonPath('message', __('partner.success.commission_updated'));

        $this->assertSame([[0, 10, 10.0], [11, 20, 12.5], [21, null, 15.0]], $this->storedTiers($this->partner));
    }

    #[Test]
    public function the_commission_is_required_and_cannot_be_cleared(): void
    {
        $this->giveTiers($this->partner, [self::tier(0, null, 30)]);
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['tiers' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tiers' => __('partner.validation.commission.required_tiers')]);

        $this->assertSame([[0, null, 30.0]], $this->storedTiers($this->partner));
    }

    public static function invalidTiers(): array
    {
        return [
            'missing' => [[], 'tiers'],
            'an empty list' => [['tiers' => []], 'tiers'],
            'not a list' => [['tiers' => 'abc'], 'tiers'],
            'a tier without its commission' => [['tiers' => [self::tier(0, 10, 10), ['min_count' => 11, 'max_count' => null, 'percentage' => null]]], 'tiers.1.percentage'],
            'not a number' => [['tiers' => [['min_count' => 0, 'max_count' => null, 'percentage' => 'abc']]], 'tiers.0.percentage'],
            'negative' => [['tiers' => [self::tier(0, null, -1)]], 'tiers.0.percentage'],
            // The admin keeps at least 1% of the success fee (save-commission).
            'leaves the admin under 1%' => [['tiers' => [self::tier(0, null, 99.5)]], 'tiers.0.percentage'],
            'first tier not from 0' => [['tiers' => [self::tier(5, null, 10)]], 'tiers.0.min_count'],
            'a gap' => [['tiers' => [self::tier(0, 10, 10), self::tier(15, null, 12)]], 'tiers.1.min_count'],
            'an overlap' => [['tiers' => [self::tier(0, 10, 10), self::tier(8, null, 12)]], 'tiers.1.min_count'],
            'end before start' => [['tiers' => [self::tier(0, 10, 10), self::tier(11, 3, 12)]], 'tiers.1.max_count'],
            'open-ended tier in the middle' => [['tiers' => [self::tier(0, null, 10), self::tier(11, null, 12)]], 'tiers.0.max_count'],
        ];
    }

    #[Test]
    #[DataProvider('invalidTiers')]
    public function invalid_tiers_are_refused_under_their_field_and_nothing_changes(array $payload, string $errorKey): void
    {
        $this->giveTiers($this->partner, [self::tier(0, null, 20)]);
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors([$errorKey]);

        $this->assertSame([[0, null, 20.0]], $this->storedTiers($this->partner));
    }

    #[Test]
    public function the_host_partner_has_no_commission_to_set(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->host->id), ['tiers' => [self::tier(0, null, 10)]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tiers' => __('partner.validation.commission.host')]);

        $this->assertSame([], $this->storedTiers($this->host));
    }

    #[Test]
    public function an_unknown_partner_is_not_found(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url(999999), ['tiers' => [self::tier(0, null, 10)]])->assertNotFound();
    }

    #[Test]
    public function the_route_checks_no_permission_yet_any_host_team_member_saves(): void
    {
        // Decision 2026-11-11: server-side permissions for this route come later.
        $this->actingAsUser($this->makeStaffUser($this->host));

        $this->putJson($this->url($this->partner->id), ['tiers' => [self::tier(0, null, 10)]])->assertOk();

        $this->assertSame([[0, null, 10.0]], $this->storedTiers($this->partner));
    }

    #[Test]
    public function only_the_hosts_team_reaches_it(): void
    {
        $payload = ['tiers' => [self::tier(0, null, 10)]];
        $this->putJson($this->url($this->partner->id), $payload)->assertUnauthorized();

        foreach ([
            $this->makePartnerAdmin($this->partner),
            $this->makePartnerAdmin($this->makeCertifiedPartner()),
            $this->makeBorrowerUser($this->host),
        ] as $outsider) {
            $this->actingAsUser($outsider);
            $this->putJson($this->url($this->partner->id), $payload)->assertForbidden();
        }

        $this->assertSame([], $this->storedTiers($this->partner));
    }

    /** The commission entries on the partner's activity log. */
    private function commissionLogs()
    {
        return ActivityLog::where('log_name', ActivityLog::LOG_TYPE_PARTNER_COMMISSION_UPDATE)
            ->where('subject_type', Partner::class)
            ->where('subject_id', $this->partner->id)
            ->orderBy('id')
            ->get();
    }

    private function assertCommissionLog(ActivityLog $log, string $event, string $description, ?string $from, ?string $to): void
    {
        $this->assertSame($event, $log->event);
        $this->assertSame($description, $log->description);
        // The host's staff only: a partner never sees the host's commission settings in its activity.
        $this->assertSame(ActivityLog::VISIBILITY_INTERNAL, $log->visibility);
        $this->assertSame($this->partner->id, (int) $log->partner_id);
        $this->assertSame($this->hostAdmin->id, (int) $log->causer_id);
        // The activity sidebar's "Show changes" table.
        $this->assertSame(['commission' => ['label' => 'Commission', 'from' => $from, 'to' => $to]], $log->properties['changes']);
    }

    #[Test]
    public function setting_a_first_commission_is_logged_as_added(): void
    {
        $this->hostAdmin->forceFill(['name' => 'Hana Host'])->save();
        $this->actingAsUser($this->hostAdmin);

        // One tier for every deal reads as a plain rate.
        $this->putJson($this->url($this->partner->id), ['tiers' => [self::tier(0, null, 25)]])->assertOk();

        $logs = $this->commissionLogs();
        $this->assertCount(1, $logs);
        $this->assertCommissionLog($logs[0], 'Commission added', 'Hana Host set the commission to 25%.', null, '25%');
    }

    #[Test]
    public function changing_the_tiers_is_logged_as_updated_with_old_and_new(): void
    {
        $this->giveTiers($this->partner, [self::tier(0, null, 20)]);
        $this->hostAdmin->forceFill(['name' => 'Hana Host'])->save();
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['tiers' => [self::tier(0, 10, 20), self::tier(11, null, 27.5)]])->assertOk();

        $logs = $this->commissionLogs();
        $this->assertCount(1, $logs);
        $this->assertCommissionLog(
            $logs[0],
            'Commission updated',
            'Hana Host changed the commission from 20% to 0-10: 20%, 11+: 27.5%.',
            '20%',
            '0-10: 20%, 11+: 27.5%'
        );
    }

    #[Test]
    public function saving_the_same_tiers_logs_nothing(): void
    {
        $this->giveTiers($this->partner, [self::tier(0, 10, 20), self::tier(11, null, 25)]);
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['tiers' => [self::tier(0, 10, 20.00), self::tier(11, null, 25)]])->assertOk();
        $this->putJson($this->url($this->partner->id), ['tiers' => [
            ['min_count' => '0', 'max_count' => '10', 'percentage' => '20'],
            ['min_count' => '11', 'max_count' => null, 'percentage' => '25'],
        ]])->assertOk();

        $this->assertCount(0, $this->commissionLogs());
    }

    #[Test]
    public function a_refused_commission_logs_nothing(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['tiers' => [self::tier(0, null, 150)]])->assertStatus(422);
        $this->putJson($this->url($this->host->id), ['tiers' => [self::tier(0, null, 10)]])->assertStatus(422);

        $this->assertCount(0, ActivityLog::where('log_name', ActivityLog::LOG_TYPE_PARTNER_COMMISSION_UPDATE)->get());
    }

    #[Test]
    public function the_entry_shows_in_the_partners_activity_for_the_host_with_its_changes(): void
    {
        $this->actingAsUser($this->hostAdmin);
        $this->putJson($this->url($this->partner->id), ['tiers' => [self::tier(0, null, 25)]])->assertOk();

        $log = $this->commissionLogs()->first();
        $this->assertTrue(ActivityLog::query()->visibleTo($this->hostAdmin)->whereKey($log->id)->exists());
        $this->assertFalse(ActivityLog::query()->visibleTo($this->makePartnerAdmin($this->partner))->whereKey($log->id)->exists());
    }

    #[Test]
    public function the_partner_details_carry_the_tiers_and_the_funded_deal_count(): void
    {
        $this->giveTiers($this->partner, [self::tier(0, 10, 12.5), self::tier(11, null, 15)]);
        $this->applicationFor($this->partner, '2026-01-01');
        $this->applicationFor($this->partner, null, FundingApplication::IN_VERIFICATION);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson("/api/v1/admin/partners-details/{$this->partner->id}")
            ->assertOk()
            ->assertJsonPath('data.commission_tiers.0.percentage', 12.5)
            ->assertJsonPath('data.commission_tiers.1.min_count', 11)
            ->assertJsonPath('data.funded_deal_count', 1);
    }

    #[Test]
    public function a_partner_with_no_commission_has_no_tiers(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson("/api/v1/admin/partners-details/{$this->partner->id}")
            ->assertOk()
            ->assertJsonPath('data.commission_tiers', []);
    }

    private function applicationFor(Partner $partner, ?string $fundedDate = '2026-01-01', string $status = FundingApplication::CLOSED_COMPLETED): FundingApplication
    {
        return FundingApplication::forceCreate([
            'borrower_id' => $this->makeBorrower($partner)->id,
            'partner_id' => $partner->id,
            'status' => $status,
            'requested_amount' => 100000,
            'funded_date' => $fundedDate,
        ]);
    }

    private function defaultCommissionOf(FundingApplication $application)
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        return $this->getJson("/api/v1/common/request-application/funding-application/{$application->id}")
            ->assertOk()
            ->json('data.partner_default_commission');
    }

    #[Test]
    public function a_funding_application_opens_its_split_at_the_tier_for_that_deal(): void
    {
        $this->giveTiers($this->partner, [self::tier(0, 1, 10), self::tier(2, null, 20)]);
        $first = $this->applicationFor($this->partner, '2026-01-01');
        $second = $this->applicationFor($this->partner, '2026-02-01');

        // Deal #1 sits in 0-1, deal #2 in 2+ - and paying deal #2 later never
        // moves deal #1 out of its tier.
        $this->assertEquals(10, $this->defaultCommissionOf($first));
        $this->assertEquals(20, $this->defaultCommissionOf($second));
    }

    #[Test]
    public function an_application_not_funded_yet_is_the_partners_next_deal(): void
    {
        $this->giveTiers($this->partner, [self::tier(0, 1, 10), self::tier(2, null, 20)]);
        $this->applicationFor($this->partner, '2026-01-01');
        $open = $this->applicationFor($this->partner, null, FundingApplication::IN_VERIFICATION);

        $this->assertEquals(20, $this->defaultCommissionOf($open));
    }

    #[Test]
    public function only_the_partners_own_funded_deals_count(): void
    {
        $this->giveTiers($this->partner, [self::tier(0, 1, 10), self::tier(2, null, 20)]);
        $other = $this->makeWhiteLabelPartner();
        $this->applicationFor($other, '2026-01-01');
        $this->applicationFor($other, '2026-01-02');

        $this->assertEquals(10, $this->defaultCommissionOf($this->applicationFor($this->partner, null, FundingApplication::IN_VERIFICATION)));
    }

    private function setupOf(FundingApplication $application)
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        return $this->getJson("/api/v1/common/request-application/funding-application/{$application->id}")
            ->assertOk()
            ->json('data.partner_commission_setup');
    }

    #[Test]
    public function the_closing_modal_gets_the_partners_tiers_and_this_deals_number(): void
    {
        $this->giveTiers($this->partner, [self::tier(0, 1, 10), self::tier(2, null, 20)]);
        $this->applicationFor($this->partner, '2026-01-01');
        $open = $this->applicationFor($this->partner, null, FundingApplication::IN_VERIFICATION);

        $setup = $this->setupOf($open);

        $this->assertSame(2, $setup['deal_position']);
        $this->assertSame(1, $setup['funded_deal_count']);
        $this->assertSame([[0, 1, 10.0], [2, null, 20.0]], collect($setup['tiers'])
            ->map(fn ($t) => [$t['min_count'], $t['max_count'], (float) $t['percentage']])->all());
    }

    #[Test]
    public function a_partner_with_no_commission_set_still_gets_the_section_with_no_tiers(): void
    {
        $setup = $this->setupOf($this->applicationFor($this->partner, null, FundingApplication::IN_VERIFICATION));

        $this->assertSame([], $setup['tiers']);
        $this->assertSame(1, $setup['deal_position']);
    }

    #[Test]
    public function a_host_client_has_no_partner_commission_section(): void
    {
        $this->giveTiers($this->host, [self::tier(0, null, 15)]);

        $this->assertNull($this->setupOf($this->applicationFor($this->host)));
    }

    #[Test]
    public function a_partner_with_no_tiers_opens_with_no_default(): void
    {
        $this->assertNull($this->defaultCommissionOf($this->applicationFor($this->partner)));
    }

    #[Test]
    public function a_host_clients_funding_application_has_no_partner_commission(): void
    {
        // Even stray tiers on the host are ignored: the admin takes it all.
        $this->giveTiers($this->host, [self::tier(0, null, 15)]);

        $this->assertNull($this->defaultCommissionOf($this->applicationFor($this->host)));
    }

    #[Test]
    public function an_existing_commission_becomes_one_tier_for_every_deal(): void
    {
        $this->partner->forceFill(['commission' => 30])->save();
        $this->makeWhiteLabelPartner()->forceFill(['commission' => null])->save();

        $migration = require database_path('migrations/2026_11_15_000002_move_partner_commission_into_tiers.php');
        $migration->up();

        $this->assertSame([[0, null, 30.0]], $this->storedTiers($this->partner));
        $this->assertSame(1, PartnerCommissionTier::count());
    }
}
