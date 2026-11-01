<?php

namespace Tests\Feature\Dashboard;

use App\Models\Borrower\FundingApplication;
use App\Models\Partner\Partner;
use App\Services\FCH\Admin\Dashboard\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Defect (BUG-013, OP-DASH-04): the owner dashboard's "Recent Funding
 * Applications" showed only applications filed under the host partner itself,
 * so the newest ones - every white-label partner's clients - were missing,
 * although Intake & Review > Funding Application (where "View All" leads) lists
 * them. The host sees the newest across all partners; a partner sees its own.
 */
#[CoversClass(DashboardService::class)]
class RecentFundingApplicationsWidgetTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private Partner $host;

    private Partner $wanda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->wanda = $this->makeWhiteLabelPartner();
    }

    private function application(Partner $partner, string $createdAt, string $status = FundingApplication::PENDING): int
    {
        return DB::table('funding_applications')->insertGetId([
            'application_number' => 'FUND-'.uniqid(),
            'borrower_id' => $this->makeBorrower($partner)->id,
            'partner_id' => $partner->id,
            'status' => $status,
            'requested_amount' => 20000,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function widget($viewer): array
    {
        $this->actingAsUser($viewer);

        return collect($this->getJson('/api/v1/common/dashboard')->assertOk()->json('data.latest_funding_applications'))
            ->pluck('id')
            ->all();
    }

    #[Test]
    public function the_host_sees_the_newest_applications_across_every_partner(): void
    {
        $hostOwn = $this->application($this->host, '2026-09-22 10:00:00', FundingApplication::DRAFT);
        $wandaNewest = $this->application($this->wanda, '2026-10-14 10:00:00', FundingApplication::CLOSED_COMPLETED);
        $wandaOlder = $this->application($this->wanda, '2026-10-05 10:00:00');

        $this->assertSame([$wandaNewest, $wandaOlder, $hostOwn], $this->widget($this->makePartnerAdmin($this->host)));
    }

    #[Test]
    public function the_host_widget_shows_at_most_five(): void
    {
        foreach (range(1, 6) as $day) {
            $this->application($this->wanda, "2026-10-0{$day} 10:00:00");
        }

        $this->assertCount(5, $this->widget($this->makePartnerAdmin($this->host)));
    }

    #[Test]
    public function a_partner_sees_only_its_own_applications(): void
    {
        $this->application($this->host, '2026-10-14 10:00:00');
        $mine = $this->application($this->wanda, '2026-10-05 10:00:00');

        $this->assertSame([$mine], $this->widget($this->makePartnerAdmin($this->wanda)));
    }

    #[Test]
    public function an_application_still_on_hold_is_not_listed(): void
    {
        $this->application($this->wanda, '2026-10-14 10:00:00', FundingApplication::ON_HOLD);
        $submitted = $this->application($this->wanda, '2026-10-05 10:00:00');

        $this->assertSame([$submitted], $this->widget($this->makePartnerAdmin($this->host)));
    }
}
