<?php

namespace Tests\Feature\Application;

use App\Http\Controllers\Api\V1\FCH\Common\Application\RequestApplicationController;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Application\RequestApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Request 2026-11-05: Intake & Review > Pre-Qualification > "Funding Type"
 * filter offers the common funding types (GET /common/funding-types - the
 * lender product categories). A pre-qualification stores the purpose its form
 * asked for (purpose_of_funding: "Equipment", "Working Capital", ...), so a
 * funding type finds the pre-qualifications whose purpose has the same
 * meaningful words (FundingTypeName): "Equipment Financing" finds "Equipment".
 */
#[CoversClass(RequestApplicationController::class)]
#[CoversClass(RequestApplicationService::class)]
class PreQualificationFundingTypeFilterTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    /** @var array<string, int> purpose => pre-qualification id */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $partner = $this->makeCertifiedPartner();
        foreach (['Equipment', 'Working Capital', 'Debt Refinance / Consolidation', 'Real Estate', 'Other'] as $purpose) {
            $this->ids[$purpose] = $this->preQual($partner, $purpose);
        }
        $this->actingAsUser($this->makePartnerAdmin($this->makeHostPartner()));
    }

    /** @return array<string, array{string, array<int, string>}> */
    public static function fundingTypes(): array
    {
        return [
            'a category for a shorter purpose' => ['Equipment Financing', ['Equipment']],
            'the same name' => ['Working Capital', ['Working Capital']],
            'words in another order' => ['Debt Consolidation / Refinance', ['Debt Refinance / Consolidation']],
            'no pre-qualification has it' => ['Term Loan', []],
            'other letter case' => ['working capital', ['Working Capital']],
        ];
    }

    #[Test]
    #[DataProvider('fundingTypes')]
    public function a_funding_type_finds_the_pre_qualifications_with_that_purpose(string $fundingType, array $purposes): void
    {
        $found = array_column($this->list(['funding_type' => $fundingType]), 'id');

        $this->assertEqualsCanonicalizing(array_map(fn ($p) => $this->ids[$p], $purposes), $found);
    }

    #[Test]
    public function no_funding_type_lists_them_all(): void
    {
        $this->assertCount(5, $this->list(['funding_type' => '']));
    }

    private function list(array $filters): array
    {
        return $this->postJson('/api/v1/common/request-application/pre-qualification', $filters)->assertOk()->json('data');
    }

    private function preQual(Partner $partner, string $purpose): int
    {
        return DB::table('pre_qualification_requests')->insertGetId([
            'borrower_id' => $this->makeBorrower($partner)->id,
            'partner_id' => $partner->id,
            'status' => PreQualificationRequest::PENDING,
            'purpose_of_funding' => $purpose,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
