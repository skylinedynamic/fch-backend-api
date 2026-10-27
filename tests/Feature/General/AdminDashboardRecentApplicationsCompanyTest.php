<?php

namespace Tests\Feature\General;

use App\Http\Resources\FCH\Common\Dashboard\DashboardFundingApplicationResource;
use App\Models\Borrower\FundingApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The admin dashboard's Recent Funding Applications names each application's
 * company next to the borrower: one person can apply for several companies,
 * and staff once emailed the wrong one. Entity type is still sent.
 */
#[CoversClass(DashboardFundingApplicationResource::class)]
class AdminDashboardRecentApplicationsCompanyTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    #[Test]
    public function each_recent_application_carries_its_own_company(): void
    {
        $host = $this->makeHostPartner();
        $admin = $this->makePartnerAdmin($host);

        foreach (['Alpha Tech Solutions' => 'S-Corporation', 'Chen Logistics Inc' => 'LLC'] as $company => $entity) {
            FundingApplication::forceCreate([
                'partner_id' => $host->id,
                'full_name' => 'Michael Chen',
                'business_name' => $company,
                'entity_type' => $entity,
                'status' => FundingApplication::CLOSED_COMPLETED,
            ]);
        }

        $this->actingAsUser($admin);
        $rows = collect($this->getJson('/api/v1/common/dashboard')->assertOk()->json('data.latest_funding_applications'));

        $this->assertEqualsCanonicalizing(
            [
                ['borrower' => 'Michael Chen', 'business_name' => 'Alpha Tech Solutions', 'entity_type' => 'S-Corporation'],
                ['borrower' => 'Michael Chen', 'business_name' => 'Chen Logistics Inc', 'entity_type' => 'LLC'],
            ],
            $rows->map(fn ($row) => [
                'borrower' => $row['borrower'],
                'business_name' => $row['business_name'] ?? null,
                'entity_type' => $row['entity_type'],
            ])->all()
        );
    }
}
