<?php

namespace Tests\Feature\Funding;

use App\Http\Controllers\Api\V1\Global\General\FundingSequenceOptionController;
use App\Http\Requests\Api\V1\Global\General\FetchFundingSequenceOptionsRequest;
use App\Http\Resources\Global\General\FundingSequenceOptionResource;
use App\Models\Funding\FundingSequencing;
use App\Services\Global\General\FundingSequenceOptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CreatesFundingTypes;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The Funding Sequence dropdown on the funding application's Funding Request
 * step lists the funding sequences offered for the application's Preferred
 * Funding Type: GET /api/v1/common/funding-sequences?funding_type=Term Loan.
 * Only Active sequences, of an Active funding type, in an Active group.
 */
#[CoversClass(FundingSequenceOptionController::class)]
#[CoversClass(FetchFundingSequenceOptionsRequest::class)]
#[CoversClass(FundingSequenceOptionResource::class)]
#[CoversClass(FundingSequenceOptionService::class)]
#[CoversClass(FundingSequencing::class)]
class FundingSequenceOptionsTest extends TestCase
{
    use CreatesFundingTypes;
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private int $termLoans;

    private int $fastTermLoans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFundingTypes(['Term Loan', 'Business Line of Credit']);
        DB::table('lender_product_categories')->insert([
            'name' => 'Old Product', 'status' => 'Inactive', 'sort_order' => 9, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $active = $this->group('Business Loans', 'Active');
        $inactive = $this->group('Retired', 'Inactive');

        $this->termLoans = $this->sequence('Term Loans', 'Term Loan', $active, sortOrder: 2);
        $this->fastTermLoans = $this->sequence('Fast Term Loans', 'Term Loan', $active, sortOrder: 1);
        $this->sequence('Paused Term Loans', 'Term Loan', $active, status: 'Inactive');
        $this->sequence('Retired Term Loans', 'Term Loan', $inactive);
        $deleted = $this->sequence('Deleted Term Loans', 'Term Loan', $active);
        DB::table('funding_sequencings')->where('id', $deleted)->update(['deleted_at' => now()]);
        $this->sequence('Business Lines of Credit', 'Business Line of Credit', $active);
        $this->sequence('Old Sequence', 'Old Product', $active);
    }

    #[Test]
    public function it_lists_the_active_sequences_of_the_funding_type_in_their_order(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->makeHostPartner()));

        $this->getJson('/api/v1/common/funding-sequences?funding_type=Term Loan')
            ->assertOk()
            ->assertExactJson([
                'status' => true,
                'message' => __('funding_sequencing.options_fetched'),
                'data' => [
                    ['id' => $this->fastTermLoans, 'name' => 'Fast Term Loans'],
                    ['id' => $this->termLoans, 'name' => 'Term Loans'],
                ],
            ]);
    }

    #[Test]
    public function a_funding_type_that_is_not_active_has_no_sequences(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->makeHostPartner()));

        $this->getJson('/api/v1/common/funding-sequences?funding_type=Old Product')
            ->assertOk()
            ->assertJsonPath('data', []);

        // A name that is no funding type at all.
        $this->getJson('/api/v1/common/funding-sequences?funding_type=Something Else')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    #[Test]
    public function an_older_name_gets_the_sequences_of_the_active_type_it_means(): void
    {
        // Applications saved before the funding types were the lender product
        // categories hold "Line of Credit" - "Business Line of Credit" now.
        $this->actingAsUser($this->makePartnerAdmin($this->makeHostPartner()));

        $this->getJson('/api/v1/common/funding-sequences?funding_type=Line of Credit')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Business Lines of Credit']);
    }

    #[Test]
    public function the_host_partners_and_borrowers_can_all_list_them(): void
    {
        $host = $this->makeHostPartner();
        $whiteLabel = $this->makeWhiteLabelPartner();

        foreach ([$this->makePartnerAdmin($host), $this->makePartnerAdmin($whiteLabel), $this->makeBorrowerUser($whiteLabel)] as $user) {
            $this->actingAsUser($user);

            $this->getJson('/api/v1/common/funding-sequences?funding_type=Business Line of Credit')
                ->assertOk()
                ->assertJsonPath('data.0.name', 'Business Lines of Credit');
        }
    }

    #[Test]
    public function a_visitor_who_is_not_signed_in_cannot_list_them(): void
    {
        $this->getJson('/api/v1/common/funding-sequences?funding_type=Term Loan')->assertUnauthorized();
    }

    #[Test]
    public function the_funding_type_is_required(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->makeHostPartner()));

        $this->getJson('/api/v1/common/funding-sequences')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('funding_type');
    }

    #[Test]
    public function a_failure_loading_them_is_a_server_error_and_logged(): void
    {
        Log::spy();
        $this->mock(FundingSequenceOptionService::class, function ($service) {
            $service->shouldReceive('forFundingType')->andThrow(new RuntimeException('database gone'));
        });
        $this->actingAsUser($this->makePartnerAdmin($this->makeHostPartner()));

        $this->getJson('/api/v1/common/funding-sequences?funding_type=Term Loan')
            ->assertStatus(500)
            ->assertJsonPath('message', __('funding_sequencing.options_fetch_failed'));

        Log::shouldHaveReceived('error')->once();
    }

    private function group(string $name, string $status): int
    {
        return DB::table('funding_groups')->insertGetId([
            'name' => $name, 'status' => $status, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sequence(string $name, string $type, int $groupId, string $status = 'Active', int $sortOrder = 5): int
    {
        return DB::table('funding_sequencings')->insertGetId([
            'name' => $name,
            'subtitle' => 'Test sequence',
            'amount_range' => '10000-250000',
            'funding_group_id' => $groupId,
            'funding_type_id' => DB::table('lender_product_categories')->where('name', $type)->value('id'),
            'status' => $status,
            'sort_order' => $sortOrder,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
