<?php

namespace Tests\Unit\Services;

use App\Services\FCH\Common\Commission\CommissionTierResolver;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which count tier a deal falls in. A deal's position is 1-based (the first
 * funded deal is #1), so "0-10" holds deals #1 to #10.
 */
#[CoversClass(CommissionTierResolver::class)]
class CommissionTierResolverTest extends TestCase
{
    private function tiers(): Collection
    {
        return collect([
            (object) ['min_count' => 11, 'max_count' => 20, 'rate' => 'b'],
            (object) ['min_count' => 0, 'max_count' => 10, 'rate' => 'a'],
            (object) ['min_count' => 21, 'max_count' => null, 'rate' => 'c'],
        ]);
    }

    public static function positions(): array
    {
        return [
            'first deal' => [1, 'a'],
            'last of the first tier' => [10, 'a'],
            'first of the second tier' => [11, 'b'],
            'last of the second tier' => [20, 'b'],
            'open-ended tier' => [21, 'c'],
            'far into the open-ended tier' => [500, 'c'],
        ];
    }

    #[Test]
    #[DataProvider('positions')]
    public function a_deal_falls_in_the_tier_its_position_is_in(int $position, string $rate): void
    {
        $this->assertSame($rate, CommissionTierResolver::pick($this->tiers(), $position)?->rate);
    }

    #[Test]
    public function no_tiers_means_no_tier(): void
    {
        $this->assertNull(CommissionTierResolver::pick(collect(), 1));
    }

    #[Test]
    public function a_position_past_a_closed_last_tier_has_no_tier(): void
    {
        $tiers = collect([(object) ['min_count' => 0, 'max_count' => 10]]);

        $this->assertNull(CommissionTierResolver::pick($tiers, 11));
    }
}
