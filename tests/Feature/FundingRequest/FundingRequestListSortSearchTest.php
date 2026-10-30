<?php

namespace Tests\Feature\FundingRequest;

use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\FundingRequest\FundingRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Owner > Intake & Review > Funding Request (/common/funding-request): every
 * sortable column sorts by what it shows, both ways, and the search finds what
 * is on screen.
 *
 * Defects: ASSIGNED LENDER was not a sortable field, so clicking it fell back
 * to newest first; BORROWER NAME sorted on the first name only; rows with an
 * equal value (many $300,000 requests) had no fixed order between pages; the
 * search ignored the assigned lender and the amount as shown ("$300,000").
 */
#[CoversClass(FundingRequestService::class)]
class FundingRequestListSortSearchTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private User $admin;

    /** @var array<string, int> request id by label */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = $this->makeHostPartner();
        $this->admin = $this->makePartnerAdmin($host);

        $this->rows['A'] = $this->request('Amy', 'Zed', 'Partner Alpha', 'Bridge Loans', 'Bridge Financing', 300000, 'Bluevine Capital', FundingRequest::STATUS_APPROVED);
        $this->rows['B'] = $this->request('Amy', 'Adams', 'Partner Charlie', 'Merchant Cash Advance', 'Merchant Cash Advance', 500000, 'Fundbox Financing', FundingRequest::STATUS_PENDING);
        $this->rows['C'] = $this->request('Zoe', 'Brown', 'Partner Bravo', 'Working Capital Equipment', 'Equipment Financing', 400000, 'OnDeck', FundingRequest::STATUS_ACCEPTED);
    }

    private function request(string $first, string $last, string $partnerName, string $sequence, string $type, int $amount, string $lender, string $status): int
    {
        $partner = $this->makeWhiteLabelPartner(['full_name' => $partnerName]);
        $borrower = $this->makeBorrower($partner, ['first_name' => $first, 'last_name' => $last]);

        return DB::table('funding_requests')->insertGetId([
            'borrower_id' => $borrower->id,
            'partner_id' => $partner->id,
            'funding_sequence_id' => DB::table('funding_sequencings')->insertGetId(['name' => $sequence, 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]),
            'funding_type' => $type,
            'requested_amount' => $amount,
            'assign_lender' => DB::table('lenders')->insertGetId(['name' => $lender, 'created_at' => now(), 'updated_at' => now()]),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function list(array $payload): array
    {
        $this->actingAsUser($this->admin);

        return collect($this->postJson('/api/v1/common/funding-request', $payload)->assertOk()->json('data'))->pluck('id')->all();
    }

    /** @return array<string, array{string, string}> column => [sort field, labels in ascending order] */
    public static function columns(): array
    {
        return [
            'Borrower Name (first, then last name)' => ['borrower_name', 'BAC'],
            'Partner Name' => ['partner_name', 'ACB'],
            'Funding Sequence (by name)' => ['funding_sequence_id', 'ABC'],
            'Funding Type' => ['funding_type', 'ACB'],
            'Requested Amount' => ['requested_amount', 'ACB'],
            'Assigned Lender' => ['lender_names', 'ABC'],
            'Request Status' => ['status', 'CAB'],
        ];
    }

    #[Test]
    #[DataProvider('columns')]
    public function each_column_sorts_both_ways_by_what_it_shows(string $field, string $ascending): void
    {
        $expected = array_map(fn (string $label) => $this->rows[$label], str_split($ascending));

        $this->assertSame($expected, $this->list(['sort_field' => $field, 'sort_order' => 'asc']));
        $this->assertSame(array_reverse($expected), $this->list(['sort_field' => $field, 'sort_order' => 'desc']));
    }

    #[Test]
    public function equal_values_keep_one_order_across_pages(): void
    {
        DB::table('funding_requests')->update(['requested_amount' => 300000]);

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $seen = array_merge($seen, $this->list(['sort_field' => 'requested_amount', 'sort_order' => 'asc', 'per_page' => 1, 'page' => $page]));
        }

        $this->assertEqualsCanonicalizing(array_values($this->rows), $seen);
    }

    /** @return array<string, array{string, string}> what is typed => labels found */
    public static function searches(): array
    {
        return [
            'borrower full name' => ['Amy Zed', 'A'],
            'borrower first name' => ['Amy', 'AB'],
            'partner name' => ['Partner Bravo', 'C'],
            'funding sequence' => ['Working Capital', 'C'],
            'funding type' => ['Bridge Financing', 'A'],
            'assigned lender' => ['Fundbox', 'B'],
            'amount as shown' => ['$500,000', 'B'],
            'amount as digits' => ['400000', 'C'],
            'request status' => ['Pending', 'B'],
        ];
    }

    #[Test]
    #[DataProvider('searches')]
    public function the_search_finds_what_the_list_shows(string $term, string $labels): void
    {
        $expected = array_map(fn (string $label) => $this->rows[$label], str_split($labels));

        $this->assertEqualsCanonicalizing($expected, $this->list(['search' => $term]));
    }

    /**
     * BUG-150: BORROWER NAME sorted on the first name, then the last - so with
     * "QA-TEST" + "R2 Wanda" and "QA-TEST ONB" + "Wanda x11" the R2 row came
     * first, though the column reads "QA-TEST ONB..." before "QA-TEST R2...".
     * It sorts on the name as the column shows it.
     */
    #[Test]
    public function borrower_name_sorts_on_the_name_as_the_column_shows_it(): void
    {
        DB::table('funding_requests')->delete();
        $r2 = $this->request('QA-TEST', 'R2 Wanda Embed Two', 'Partner R2', 'Seq R2', 'Bridge Financing', 1000, 'Lender R2', FundingRequest::STATUS_PENDING);
        $onb = $this->request('QA-TEST ONB', 'Wanda x11', 'Partner ONB', 'Seq ONB', 'Bridge Financing', 1000, 'Lender ONB', FundingRequest::STATUS_PENDING);

        $this->assertSame([$onb, $r2], $this->list(['sort_field' => 'borrower_name', 'sort_order' => 'asc']));
        $this->assertSame([$r2, $onb], $this->list(['sort_field' => 'borrower_name', 'sort_order' => 'desc']));
    }

    /**
     * BUG-150: APPLICATION STATUS toggled its arrow but the rows never moved -
     * it was not a sortable field, so it fell back to newest first. It follows
     * the application lifecycle (no application yet, Draft, ... Funded), as the
     * borrower's own list does (BUG-009).
     */
    #[Test]
    public function application_status_sorts_both_ways_by_the_application_lifecycle(): void
    {
        foreach (['A' => FundingApplication::FUNDED, 'B' => FundingApplication::DRAFT] as $label => $status) {
            $request = DB::table('funding_requests')->find($this->rows[$label]);
            DB::table('funding_applications')->insert([
                'funding_request_id' => $request->id,
                'borrower_id' => $request->borrower_id,
                'partner_id' => $request->partner_id,
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // C has no application yet, B is a Draft, A is Funded.
        $this->assertSame([$this->rows['C'], $this->rows['B'], $this->rows['A']], $this->list(['sort_field' => 'application_status', 'sort_order' => 'asc']));
        $this->assertSame([$this->rows['A'], $this->rows['B'], $this->rows['C']], $this->list(['sort_field' => 'application_status', 'sort_order' => 'desc']));
    }
}
