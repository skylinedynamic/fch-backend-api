<?php

namespace Tests\Unit\Partner;

use App\Models\Partner\PartnerCommissionTier;
use App\Services\FCH\Partner\CommissionTierFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(CommissionTierFormatter::class)]
class CommissionTierFormatterTest extends TestCase
{
    #[Test]
    public function a_flat_tier_is_dollars_without_empty_cents(): void
    {
        $this->assertSame('$1,500', CommissionTierFormatter::value(['commission_type' => PartnerCommissionTier::TYPE_FLAT, 'amount' => '1500.00']));
        $this->assertSame('$1,250.50', CommissionTierFormatter::value(['commission_type' => PartnerCommissionTier::TYPE_FLAT, 'amount' => 1250.5]));
    }

    #[Test]
    public function a_percentage_tier_drops_trailing_zeros(): void
    {
        $this->assertSame('27.5%', CommissionTierFormatter::value(['commission_type' => PartnerCommissionTier::TYPE_PERCENTAGE, 'percentage' => '27.50']));
        $this->assertSame('25%', CommissionTierFormatter::value(['percentage' => 25]));
    }

    #[Test]
    public function the_last_tier_runs_and_up(): void
    {
        $this->assertSame('and up', CommissionTierFormatter::maxLabel(null));
        $this->assertSame('10', CommissionTierFormatter::maxLabel(10));
    }
}
