<?php

namespace Tests\Feature\Partner;

use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerController;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdatePartnerCommissionRequest;
use App\Http\Resources\FCH\Common\Application\FundingApplicationResource;
use App\Http\Resources\FCH\Partner\PartnerDetailsResource;
use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\FundingApplication;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Partner profile › Commissions: the host sets a partner's commission - the
 * partner's share (%) of the success fee on its clients' funding applications.
 * The funding application carries it so the commission split opens at it.
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

    #[Test]
    public function the_host_sets_a_partners_commission(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['commission' => 25.5])
            ->assertOk()
            ->assertJsonPath('data.id', $this->partner->id)
            ->assertJsonPath('data.commission', 25.5)
            ->assertJsonPath('message', __('partner.success.commission_updated'));

        $this->assertEquals(25.5, $this->partner->fresh()->commission);
    }

    #[Test]
    public function the_host_clears_a_partners_commission(): void
    {
        $this->partner->forceFill(['commission' => 30])->save();
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['commission' => null])
            ->assertOk()
            ->assertJsonPath('data.commission', null);

        $this->assertNull($this->partner->fresh()->commission);
    }

    public static function invalidCommissions(): array
    {
        return [
            'missing' => [[]],
            'not a number' => [['commission' => 'abc']],
            'negative' => [['commission' => -1]],
            // The admin keeps at least 1% of the success fee (save-commission).
            'leaves the admin under 1%' => [['commission' => 99.5]],
            'over 100' => [['commission' => 101]],
        ];
    }

    #[Test]
    #[DataProvider('invalidCommissions')]
    public function an_invalid_commission_is_refused_and_nothing_changes(array $payload): void
    {
        $this->partner->forceFill(['commission' => 20])->save();
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['commission']);

        $this->assertEquals(20, $this->partner->fresh()->commission);
    }

    #[Test]
    public function the_host_partner_has_no_commission_to_set(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->host->id), ['commission' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['commission' => __('partner.validation.commission.host')]);

        $this->assertNull($this->host->fresh()->commission);
    }

    #[Test]
    public function an_unknown_partner_is_not_found(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url(999999), ['commission' => 10])->assertNotFound();
    }

    #[Test]
    public function the_route_checks_no_permission_yet_any_host_team_member_saves(): void
    {
        // Decision 2026-11-11: server-side permissions for this route come later.
        $this->actingAsUser($this->makeStaffUser($this->host));

        $this->putJson($this->url($this->partner->id), ['commission' => 10])->assertOk();

        $this->assertEquals(10, $this->partner->fresh()->commission);
    }

    #[Test]
    public function only_the_hosts_team_reaches_it(): void
    {
        $this->putJson($this->url($this->partner->id), ['commission' => 10])->assertUnauthorized();

        foreach ([
            $this->makePartnerAdmin($this->partner),
            $this->makePartnerAdmin($this->makeCertifiedPartner()),
            $this->makeBorrowerUser($this->host),
        ] as $outsider) {
            $this->actingAsUser($outsider);
            $this->putJson($this->url($this->partner->id), ['commission' => 10])->assertForbidden();
        }

        $this->assertNull($this->partner->fresh()->commission);
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

        $this->putJson($this->url($this->partner->id), ['commission' => 25])->assertOk();

        $logs = $this->commissionLogs();
        $this->assertCount(1, $logs);
        $this->assertCommissionLog($logs[0], 'Commission added', 'Hana Host set the commission to 25%.', null, '25%');
    }

    #[Test]
    public function changing_the_commission_is_logged_as_updated_with_old_and_new(): void
    {
        $this->partner->forceFill(['commission' => 20])->save();
        $this->hostAdmin->forceFill(['name' => 'Hana Host'])->save();
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['commission' => 27.5])->assertOk();

        $logs = $this->commissionLogs();
        $this->assertCount(1, $logs);
        $this->assertCommissionLog($logs[0], 'Commission updated', 'Hana Host changed the commission from 20% to 27.5%.', '20%', '27.5%');
    }

    #[Test]
    public function clearing_the_commission_is_logged_as_removed(): void
    {
        $this->partner->forceFill(['commission' => 20])->save();
        $this->hostAdmin->forceFill(['name' => 'Hana Host'])->save();
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['commission' => null])->assertOk();

        $logs = $this->commissionLogs();
        $this->assertCount(1, $logs);
        $this->assertCommissionLog($logs[0], 'Commission removed', 'Hana Host removed the commission (was 20%).', '20%', null);
    }

    #[Test]
    public function saving_the_same_commission_logs_nothing(): void
    {
        $this->partner->forceFill(['commission' => 20])->save();
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['commission' => 20.00])->assertOk();
        $this->putJson($this->url($this->partner->id), ['commission' => '20'])->assertOk();

        $this->assertCount(0, $this->commissionLogs());
    }

    #[Test]
    public function a_refused_commission_logs_nothing(): void
    {
        $this->actingAsUser($this->hostAdmin);

        $this->putJson($this->url($this->partner->id), ['commission' => 150])->assertStatus(422);
        $this->putJson($this->url($this->host->id), ['commission' => 10])->assertStatus(422);

        $this->assertCount(0, ActivityLog::where('log_name', ActivityLog::LOG_TYPE_PARTNER_COMMISSION_UPDATE)->get());
    }

    #[Test]
    public function the_entry_shows_in_the_partners_activity_for_the_host_with_its_changes(): void
    {
        $this->actingAsUser($this->hostAdmin);
        $this->putJson($this->url($this->partner->id), ['commission' => 25])->assertOk();

        $log = $this->commissionLogs()->first();
        $this->assertTrue(ActivityLog::query()->visibleTo($this->hostAdmin)->whereKey($log->id)->exists());
        $this->assertFalse(ActivityLog::query()->visibleTo($this->makePartnerAdmin($this->partner))->whereKey($log->id)->exists());
    }

    #[Test]
    public function the_partner_details_carry_the_commission(): void
    {
        $this->partner->forceFill(['commission' => 12.5])->save();
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson("/api/v1/admin/partners-details/{$this->partner->id}")
            ->assertOk()
            ->assertJsonPath('data.commission', 12.5);
    }

    #[Test]
    public function a_partner_with_no_commission_reads_as_null(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson("/api/v1/admin/partners-details/{$this->partner->id}")
            ->assertOk()
            ->assertJsonPath('data.commission', null);
    }

    private function applicationFor(Partner $partner): FundingApplication
    {
        return FundingApplication::forceCreate([
            'borrower_id' => $this->makeBorrower($partner)->id,
            'partner_id' => $partner->id,
            'status' => FundingApplication::CLOSED_COMPLETED,
            'requested_amount' => 100000,
        ]);
    }

    #[Test]
    public function a_funding_application_carries_its_partners_commission_for_the_split(): void
    {
        $this->partner->forceFill(['commission' => 40])->save();
        $application = $this->applicationFor($this->partner);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson("/api/v1/common/request-application/funding-application/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.partner_default_commission', 40);
    }

    #[Test]
    public function a_host_clients_funding_application_has_no_partner_commission(): void
    {
        // Even a stray value on the host is ignored: the admin takes it all.
        $this->host->forceFill(['commission' => 15])->save();
        $application = $this->applicationFor($this->host);
        $this->actingAsUser($this->makePartnerAdmin($this->host));

        $this->getJson("/api/v1/common/request-application/funding-application/{$application->id}")
            ->assertOk()
            ->assertJsonPath('data.partner_default_commission', null);
    }
}
