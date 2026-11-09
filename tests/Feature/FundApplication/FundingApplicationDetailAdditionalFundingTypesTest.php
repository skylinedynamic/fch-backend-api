<?php

namespace Tests\Feature\FundApplication;

use App\Http\Resources\Borrower\MyApplication\FundingApplicationResource as BorrowerFundingApplicationResource;
use App\Http\Resources\FCH\Common\Application\FundingApplicationResource as AdminFundingApplicationResource;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The funding application's details - Intake & Review's Funding Request tab and
 * the borrower's own application pages - give the additional funding types.
 */
class FundingApplicationDetailAdditionalFundingTypesTest extends TestCase
{
    use RefreshDatabase;

    private function application(array $attributes = []): FundingApplication
    {
        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ]);
        $borrower = Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => 'odessa@example.test',
        ]);

        $id = DB::table('funding_applications')->insertGetId(array_merge([
            'application_number' => 'FUND-DETAIL-1',
            'borrower_id' => $borrower->id,
            'partner_id' => $partner->id,
            'status' => FundingApplication::DRAFT,
            'preferred_funding_type' => 'Term Loan',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return FundingApplication::with('commission', 'partner', 'borrower', 'coborrowers', 'fundingDocuments', 'fundingSequence', 'fundingRequest')
            ->findOrFail($id);
    }

    public static function resources(): array
    {
        return [
            'Intake & Review details' => [AdminFundingApplicationResource::class],
            'borrower application details' => [BorrowerFundingApplicationResource::class],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('resources')]
    public function the_additional_funding_types_are_given(string $resource): void
    {
        $application = $this->application(['additional_funding_types' => json_encode(['SBA Loan', 'Line of Credit'])]);

        $data = (new $resource($application))->resolve(Request::create('/'));

        $this->assertSame('Term Loan', $data['preferred_funding_type']);
        $this->assertSame(['SBA Loan', 'Line of Credit'], $data['additional_funding_types']);
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('resources')]
    public function none_chosen_is_an_empty_list(string $resource): void
    {
        $data = (new $resource($this->application()))->resolve(Request::create('/'));

        $this->assertSame([], $data['additional_funding_types']);
    }
}
