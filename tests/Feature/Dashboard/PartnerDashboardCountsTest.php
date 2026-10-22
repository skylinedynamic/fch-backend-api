<?php

namespace Tests\Feature\Dashboard;

use App\Http\Controllers\Api\V1\FCH\Admin\Dashboard\DashboardController;
use App\Models\Borrower\FundingApplication;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The partner portal dashboard's top cards (GET /common/dashboard counts).
 * Each card shows the same total as the card on its list page: deleted and
 * archived records are left out, Total Partners never counts the host itself,
 * and a non-host partner sees only its own clients and leads.
 *
 * Defect: the host's Total Partners counted the host, and Total Clients
 * (host and partner) counted archived clients, so the dashboard disagreed
 * with the Partners and Clients pages.
 */
#[CoversClass(DashboardController::class)]
class PartnerDashboardCountsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $host;
    private Partner $whiteLabel;
    private Partner $certified;
    private User $hostAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->makeHostPartner();
        $this->whiteLabel = $this->makeWhiteLabelPartner();
        $this->certified = $this->makeCertifiedPartner();
        $this->hostAdmin = $this->makePartnerAdmin($this->host);
    }

    private function leadOf(Partner $partner): Lead
    {
        return Lead::create([
            'partner_id' => $partner->id,
            'company_name' => 'Lead Co ' . uniqid(),
            'contact_person' => 'Lee Lead',
            'email' => 'lead-' . uniqid() . '@example.test',
            'lead_type' => Lead::BORROWER_TYPE_LEAD,
            'status' => Lead::STATUS_NEW_LEAD,
        ]);
    }

    private function counts(User $user): array
    {
        $this->actingAsUser($user);

        return $this->getJson('/api/v1/common/dashboard')->assertOk()->json('data.counts');
    }

    #[Test]
    public function the_hosts_total_partners_leaves_out_the_host_and_deleted_or_archived_partners(): void
    {
        $this->makePartner()->delete();
        $this->makePartner()->forceFill(['archived_at' => now()])->save();

        $this->assertSame(2, $this->counts($this->hostAdmin)['total_partners']);
    }

    #[Test]
    public function the_hosts_total_clients_counts_every_partners_live_unarchived_clients(): void
    {
        $this->makeBorrower($this->host);
        $this->makeBorrower($this->whiteLabel);
        $this->makeBorrower($this->certified);
        $this->makeBorrower($this->whiteLabel)->delete();
        $this->makeBorrower($this->certified)->forceFill(['archived_at' => now()])->save();

        $this->assertSame(3, $this->counts($this->hostAdmin)['total_clients']);
    }

    #[Test]
    public function the_hosts_total_leads_counts_every_partners_live_leads(): void
    {
        $this->leadOf($this->host);
        $this->leadOf($this->whiteLabel);
        $this->leadOf($this->certified);
        $this->leadOf($this->whiteLabel)->delete();

        $this->assertSame(3, $this->counts($this->hostAdmin)['total_leads']);
    }

    #[Test]
    public function a_partner_counts_only_its_own_live_unarchived_clients_and_leads(): void
    {
        $this->makeBorrower($this->whiteLabel);
        $this->makeBorrower($this->whiteLabel)->delete();
        $this->makeBorrower($this->whiteLabel)->forceFill(['archived_at' => now()])->save();
        $this->makeBorrower($this->certified);
        $this->leadOf($this->whiteLabel);
        $this->leadOf($this->whiteLabel)->delete();
        $this->leadOf($this->certified);

        $counts = $this->counts($this->makePartnerAdmin($this->whiteLabel));

        $this->assertSame(1, $counts['total_clients']);
        $this->assertSame(1, $counts['total_leads']);
        $this->assertArrayNotHasKey('total_partners', $counts);
    }

    /**
     * Defect: On Hold applications were left out, so the card disagreed with
     * the Funding Applications page, which counts them.
     */
    #[Test]
    public function a_partners_funding_applications_count_includes_on_hold_and_leaves_out_deleted_and_others(): void
    {
        $borrower = $this->makeBorrower($this->whiteLabel);
        $this->applicationFor($borrower->id, $this->whiteLabel->id, FundingApplication::SUBMITTED);
        $this->applicationFor($borrower->id, $this->whiteLabel->id, FundingApplication::ON_HOLD);
        $this->applicationFor($borrower->id, $this->whiteLabel->id, FundingApplication::SUBMITTED, deleted: true);
        $other = $this->makeBorrower($this->certified);
        $this->applicationFor($other->id, $this->certified->id, FundingApplication::SUBMITTED);

        $this->assertSame(2, $this->counts($this->makePartnerAdmin($this->whiteLabel))['funding_application']);
    }

    private function applicationFor(int $borrowerId, int $partnerId, string $status, bool $deleted = false): void
    {
        DB::table('funding_applications')->insert([
            'application_number' => 'FUND-' . uniqid(),
            'borrower_id' => $borrowerId,
            'partner_id' => $partnerId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $deleted ? now() : null,
        ]);
    }
}
