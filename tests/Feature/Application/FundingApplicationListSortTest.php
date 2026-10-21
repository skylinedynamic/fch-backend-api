<?php

namespace Tests\Feature\Application;

use App\Models\Borrower\FundingApplication;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Application\RequestApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Owner > Intake & Review > Funding Application
 * (/common/request-application/funding-application): every sortable column
 * sorts both ways.
 *
 * Defect: DATE sent `sort_field=date`, which is not a column - the resource
 * formats `created_at` as "20 Oct 2026" - so the order never changed (an SQL
 * error on MySQL).
 */
#[CoversClass(RequestApplicationService::class)]
class FundingApplicationListSortTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private User $admin;

    /** @var array<string, int> application id by label */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = $this->makeHostPartner();
        $this->admin = $this->makePartnerAdmin($host);

        $this->rows['A'] = $this->application($host->id, 'Amy Zed', 'FUND-0000003', 'Retail', 'LLC', 'Working Capital', 300000, FundingApplication::PENDING, '2026-10-05 10:00:00');
        $this->rows['B'] = $this->application($host->id, 'Bob Young', 'FUND-0000001', 'Construction', 'Sole Proprietor', 'Equipment', 500000, FundingApplication::DRAFT, '2026-10-20 10:00:00');
        $this->rows['C'] = $this->application($host->id, 'Cara Xu', 'FUND-0000002', 'Manufacturing', 'Corporation', 'Payroll', 400000, FundingApplication::FUNDED, '2026-09-26 10:00:00');
    }

    private function application(int $partnerId, string $name, string $number, string $industry, string $entity, string $purpose, int $amount, string $status, string $createdAt): int
    {
        return DB::table('funding_applications')->insertGetId([
            'partner_id' => $partnerId,
            'borrower_id' => $this->makeBorrower(Partner::find($partnerId))->id,
            'full_name' => $name,
            'application_number' => $number,
            'industry_type' => $industry,
            'entity_type' => $entity,
            'purpose_of_funds' => $purpose,
            'requested_amount' => $amount,
            'status' => $status,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function list(string $field, string $order): array
    {
        $this->actingAsUser($this->admin);

        return collect($this->postJson('/api/v1/common/request-application/funding-application', [
            'sort_field' => $field,
            'sort_order' => $order,
        ])->assertOk()->json('data'))->pluck('id')->all();
    }

    /** @return array<string, array{string, string}> column => [sort field, labels in ascending order] */
    public static function columns(): array
    {
        return [
            'Name' => ['full_name', 'ABC'],
            'App No' => ['application_number', 'BCA'],
            'Industry Type' => ['industry_type', 'BCA'],
            'Entity Type' => ['entity_type', 'CAB'],
            'Purpose' => ['purpose_of_funds', 'BCA'],
            'Requested Amount' => ['requested_amount', 'ACB'],
            'Date' => ['date', 'CAB'],
        ];
    }

    #[Test]
    #[DataProvider('columns')]
    public function each_column_sorts_both_ways(string $field, string $ascending): void
    {
        $expected = array_map(fn (string $label) => $this->rows[$label], str_split($ascending));

        $this->assertSame($expected, $this->list($field, 'asc'));
        $this->assertSame(array_reverse($expected), $this->list($field, 'desc'));
    }

    /**
     * Defect: the Date Range filter was ignored - the controller never passed
     * start_date / end_date on and the list had no date filter - so 10/20 -
     * 10/20 still listed an 18 Oct application. The DATE column shows
     * created_at in the viewer's timezone, so the range is the viewer's days.
     */
    #[Test]
    public function the_date_range_keeps_only_applications_from_those_days_in_the_viewers_timezone(): void
    {
        DB::table('funding_applications')->delete();
        $host = Partner::where('is_host', true)->first();
        // Asia/Dhaka is UTC+6: 20 Oct there runs from 19 Oct 18:00 to 20 Oct 17:59:59 UTC.
        $earlyOn20th = $this->application($host->id, 'Early', 'FUND-1', 'Retail', 'LLC', 'Payroll', 1000, FundingApplication::PENDING, '2026-10-19 19:00:00');
        $lateOn20th = $this->application($host->id, 'Late', 'FUND-2', 'Retail', 'LLC', 'Payroll', 1000, FundingApplication::PENDING, '2026-10-20 17:30:00');
        $this->application($host->id, '21st', 'FUND-3', 'Retail', 'LLC', 'Payroll', 1000, FundingApplication::PENDING, '2026-10-20 18:30:00');
        $this->application($host->id, '18th', 'FUND-4', 'Retail', 'LLC', 'Payroll', 1000, FundingApplication::PENDING, '2026-10-18 10:00:00');
        $this->actingAsUser($this->admin);

        $ids = collect($this->withHeader('X-Timezone', 'Asia/Dhaka')->postJson('/api/v1/common/request-application/funding-application', [
            'start_date' => '2026-10-20',
            'end_date' => '2026-10-20',
        ])->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$earlyOn20th, $lateOn20th], $ids);
    }

    /**
     * The same two applications fall on different local days in Dhaka and in
     * New York; each viewer gets the ones their own DATE column says are the
     * 20th.
     *
     * @return array<string, array{string, string}> timezone => application on the 20th there
     */
    public static function viewers(): array
    {
        return [
            // 03:30 UTC is 09:30 on the 20th in Dhaka (UTC+6), 23:30 on the 19th in New York (UTC-4).
            'Dhaka' => ['Asia/Dhaka', 'early'],
            // 18:30 UTC is 00:30 on the 21st in Dhaka, 14:30 on the 20th in New York.
            'New York' => ['America/New_York', 'late'],
        ];
    }

    #[Test]
    #[DataProvider('viewers')]
    public function the_date_range_is_the_viewers_own_day_in_any_timezone(string $timezone, string $expected): void
    {
        DB::table('funding_applications')->delete();
        $host = Partner::where('is_host', true)->first();
        $ids = [
            'early' => $this->application($host->id, 'Early', 'FUND-1', 'Retail', 'LLC', 'Payroll', 1000, FundingApplication::PENDING, '2026-10-20 03:30:00'),
            'late' => $this->application($host->id, 'Late', 'FUND-2', 'Retail', 'LLC', 'Payroll', 1000, FundingApplication::PENDING, '2026-10-20 18:30:00'),
        ];
        $this->actingAsUser($this->admin);

        $response = $this->withHeader('X-Timezone', $timezone)->postJson('/api/v1/common/request-application/funding-application', [
            'start_date' => '2026-10-20',
            'end_date' => '2026-10-20',
        ])->assertOk();

        $this->assertSame([$ids[$expected]], collect($response->json('data'))->pluck('id')->all());
        // ... and its DATE column says the 20th, in that viewer's timezone.
        $this->assertSame('20 Oct 2026', $response->json('data.0.date'));
    }

    #[Test]
    public function status_sorts_by_the_lifecycle_both_ways(): void
    {
        // Draft (key 2) -> Submitted - Pending Review (3) -> Funded: the order
        // things happen in, not the alphabet.
        $expected = [$this->rows['B'], $this->rows['A'], $this->rows['C']];

        $this->assertSame($expected, $this->list('status', 'asc'));
        $this->assertSame(array_reverse($expected), $this->list('status', 'desc'));
    }
}
