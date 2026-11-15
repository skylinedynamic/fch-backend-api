<?php

namespace Tests\Feature\Funding;

use App\Models\Funding\FundingGroup;
use App\Models\User\User;
use App\Services\FCH\Admin\Funding\FundingSequencingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Owner > Funding Sequence list (/funding): the AMOUNT column sorts by amount.
 *
 * Defect: BUG-176 - AMOUNT sorted the stored "min-max" text, so 1000 < 10000 <
 * 100000 < 2000 (character order) instead of by number.
 */
#[CoversClass(FundingSequencingService::class)]
class FundingSequenceListAmountSortTest extends TestCase
{
    use InteractsWithPortalUsers;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makePartnerAdmin($this->makeHostPartner());
        $group = FundingGroup::create(['name' => 'Loans', 'status' => 'Active']);

        foreach (['10000-50000', '2000-100000', '100000-10000000', '10000-250000', '700000-800000000', '1000-250000'] as $range) {
            DB::table('funding_sequencings')->insert([
                'name' => "Seq {$range}",
                'funding_group_id' => $group->id,
                'amount_range' => $range,
                'status' => 'Active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return array<int, string> */
    private function ranges(string $order): array
    {
        $this->actingAsUser($this->admin);

        $response = $this->postJson('/api/v1/admin/funding-sequencings/fetch', [
            'sort_field' => 'amount_range',
            'sort_order' => $order,
            'per_page' => 50,
        ])->assertOk();

        return collect($response->json('data'))->pluck('amount_range')->all();
    }

    #[Test]
    public function amount_sorts_ascending_by_number(): void
    {
        $this->assertSame(
            ['1000-250000', '2000-100000', '10000-50000', '10000-250000', '100000-10000000', '700000-800000000'],
            $this->ranges('asc')
        );
    }

    #[Test]
    public function amount_sorts_descending_by_number(): void
    {
        $this->assertSame(
            ['700000-800000000', '100000-10000000', '10000-250000', '10000-50000', '2000-100000', '1000-250000'],
            $this->ranges('desc')
        );
    }
}
