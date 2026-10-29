<?php

namespace Tests\Feature\Funding;

use App\Http\Controllers\Api\V1\FCH\Common\Funding\FundingQualificationController;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Defect: the borrower's Funding page showed a product blocked by bank
 * statement figures as "Missing Info" with no reason. Both endpoints the page
 * reads - the funding list and a product's readiness factors - now carry the
 * bank-statement message and the upload link on the failed factor.
 */
#[CoversClass(FundingQualificationController::class)]
class BankingReadinessMessageApiTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private User $borrowerUser;

    private int $sequenceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeHostPartner();
        $partner = $this->makeWhiteLabelPartner();
        $this->borrowerUser = $this->makeBorrowerUser($partner, ['average_monthly_deposits' => 5000]);

        $lenderId = DB::table('lenders')->insertGetId([
            'name' => 'Mara Lloyd', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $groupId = DB::table('funding_groups')->insertGetId([
            'name' => 'Equipment Financing', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->sequenceId = DB::table('funding_sequencings')->insertGetId([
            'name' => 'Equipment Loans', 'funding_group_id' => $groupId, 'status' => 'Active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('lender_visibilities')->insert([
            'lender_id' => $lenderId,
            'funding_sequencing_id' => $this->sequenceId,
            'min_monthly_deposits' => 60000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function depositsFactor(array $factors): ?array
    {
        return collect($factors)->firstWhere('label', 'Minimum Monthly Deposits');
    }

    #[Test]
    public function the_readiness_factors_carry_the_bank_statement_message(): void
    {
        $this->actingAsUser($this->borrowerUser);

        $factors = $this->getJson("/api/v1/common/funding-qualification/{$this->sequenceId}/readiness-factors")
            ->assertOk()
            ->json('data');

        $factor = $this->depositsFactor($factors);
        $this->assertSame('banking', $factor['group']);
        $this->assertSame(__('funding_sequencing.bank_statements_failed'), $factor['message']);
        $this->assertSame('/documents', $factor['action']['path']);
    }

    #[Test]
    public function the_funding_list_carries_the_bank_statement_message(): void
    {
        $this->actingAsUser($this->borrowerUser);

        $response = $this->getJson('/api/v1/borrower/funding')->assertOk();

        $option = collect($response->json('data.groups'))
            ->flatMap(fn (array $group) => $group['sequencings'])
            ->firstWhere('id', $this->sequenceId);

        $this->assertNotNull($option, 'The funding option is in the borrower\'s list');
        $this->assertSame(
            __('funding_sequencing.bank_statements_failed'),
            $this->depositsFactor($option['readiness_factors'])['message'] ?? null
        );
    }
}
