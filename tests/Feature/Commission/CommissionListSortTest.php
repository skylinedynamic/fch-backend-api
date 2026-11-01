<?php

namespace Tests\Feature\Commission;

use App\Http\Controllers\Api\V1\FCH\Common\Commission\CommissionController;
use App\Models\Borrower\FundingApplication;
use App\Models\Commission\Commission;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Services\FCH\Common\Commission\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-073: sorting Commissions by Date, Borrower, Partner, Requested or Funded
 * Amount answered 500 - the table sent its display fields (date, borrower, ...)
 * as sort_field, none of them a commissions column, and the list ordered by
 * them as given. The table now sends these keys; the list sorts by each, and
 * any other field falls back to the newest first instead of failing.
 *
 * The deals (host owner sees all three):
 *   A  partner "Beta Funding", client "Carla", requested 30k, funded 10k, oldest
 *   B  partner "Acme Capital", client "Abe",   requested 10k, funded 30k
 *   C  partner "Beta Funding", client "Bea",   requested 20k, funded 20k, newest
 */
#[CoversClass(CommissionController::class)]
#[CoversClass(CommissionService::class)]
class CommissionListSortTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $owner;
    /** @var array<string, Commission> */
    private array $deals = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $host = $this->makeHostPartner();
        $beta = $this->makeWhiteLabelPartner(['full_name' => 'Beta Funding']);
        $acme = $this->makeWhiteLabelPartner(['full_name' => 'Acme Capital']);
        $this->owner = $this->makePartnerAdmin($host);

        $this->deals['A'] = $this->deal($beta, 'Carla', 30000, 10000, now()->subDays(3));
        $this->deals['B'] = $this->deal($acme, 'Abe', 10000, 30000, now()->subDays(2));
        $this->deals['C'] = $this->deal($beta, 'Bea', 20000, 20000, now()->subDay());
    }

    private function deal(Partner $partner, string $client, float $requested, float $funded, $createdAt): Commission
    {
        $borrower = $this->makeBorrower($partner);
        $application = FundingApplication::forceCreate([
            'borrower_id' => $borrower->id,
            'partner_id' => $partner->id,
            'full_name' => $client,
            'status' => FundingApplication::CLOSED_COMPLETED,
            'requested_amount' => $requested,
            'total_funded_amount' => $funded,
        ]);

        $commission = Commission::create([
            'funding_application_id' => $application->id,
            'borrower_id' => $borrower->id,
            'partner_id' => $partner->id,
            'total_commission' => 10,
            'admin_commission' => 100,
            'partner_commission' => 0,
            'total_commission_amount' => $funded / 10,
            'admin_commission_amount' => $funded / 10,
            'partner_commission_amount' => 0,
            'commission_status' => Commission::STATUS_PROCESSING,
            'payment_status' => Commission::PAYMENT_STATUS_UNPAID,
        ]);
        $commission->forceFill(['created_at' => $createdAt])->save();

        return $commission;
    }

    /** The deal letters in the order the list returns them. */
    private function sortedBy(string $field, string $order): array
    {
        $this->actingAsUser($this->owner);
        $ids = collect($this->postJson('/api/v1/common/commissions', ['sort_field' => $field, 'sort_order' => $order, 'per_page' => 50])
            ->assertOk()
            ->assertJsonStructure(['statistics'])
            ->json('data'))->pluck('id')->all();

        $letters = collect($this->deals)->mapWithKeys(fn (Commission $c, string $letter) => [$c->id => $letter]);

        return array_map(fn ($id) => $letters[$id], $ids);
    }

    /** @return iterable<string, array{string, string[]}> header sort key => ascending order */
    public static function headerSorts(): iterable
    {
        yield 'Date' => ['created_at', ['A', 'B', 'C']];
        yield 'Borrower' => ['borrower_name', ['B', 'C', 'A']];
        yield 'Requested Amount' => ['requested_amount', ['B', 'C', 'A']];
        yield 'Funded Amount' => ['funded_amount', ['A', 'C', 'B']];
    }

    #[Test]
    #[DataProvider('headerSorts')]
    public function each_header_sorts_the_list_both_ways(string $field, array $ascending): void
    {
        $this->assertSame($ascending, $this->sortedBy($field, 'asc'));
        $this->assertSame(array_reverse($ascending), $this->sortedBy($field, 'desc'));
    }

    #[Test]
    public function the_partner_header_sorts_by_partner_name(): void
    {
        $asc = $this->sortedBy('partner_name', 'asc');
        $this->assertSame('B', $asc[0]);
        $this->assertEqualsCanonicalizing(['A', 'C'], array_slice($asc, 1));

        $this->assertSame('B', $this->sortedBy('partner_name', 'desc')[2]);
    }

    #[Test]
    public function a_field_the_list_does_not_know_falls_back_to_newest_first(): void
    {
        foreach (['date', 'borrower', 'partner', 'no_such_column'] as $field) {
            $this->assertSame(['C', 'B', 'A'], $this->sortedBy($field, 'desc'), "sort_field={$field}");
        }
    }

    #[Test]
    public function the_columns_that_already_sorted_still_do(): void
    {
        $this->assertSame(['A', 'C', 'B'], $this->sortedBy('admin_commission_amount', 'asc'));
    }
}
