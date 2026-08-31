<?php

namespace Tests\Unit\Services\Borrower\CapitalAccess;

use App\Models\Borrower\Borrower;
use App\Models\Common\ScoreConfiguration;
use App\Models\CreditBuilder\BorrowerCapitalAccessPlay;
use App\Models\CreditBuilder\CapitalAccess;
use App\Services\Borrower\CapitalAccess\ApprovalProbabilityService;
use App\Services\Borrower\CapitalAccess\BorrowerContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use ReflectionClass;
use Tests\TestCase;

class ApprovalProbabilityServiceTest extends TestCase
{
    protected ApprovalProbabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->service = new ApprovalProbabilityService;
    }

    /**
     * See ApprovalOddsServiceTest for why BorrowerContext is built via
     * reflection rather than ::from() — it keeps this a DB-free unit test.
     */
    private function makeContext(array $attrs = []): BorrowerContext
    {
        $ctx = (new ReflectionClass(BorrowerContext::class))->newInstanceWithoutConstructor();

        $ctx->ficoScore = $attrs['ficoScore'] ?? 700;
        $ctx->revolvingUtilization = $attrs['revolvingUtilization'] ?? 30.0;
        $ctx->recentInquiries = $attrs['recentInquiries'] ?? 0;
        $ctx->currentTier = $attrs['currentTier'] ?? 2;
        $ctx->readinessData = $attrs['readinessData'] ?? [];
        $ctx->readinessScore = $attrs['readinessScore'] ?? 50;
        $ctx->bankingLabel = $attrs['bankingLabel'] ?? null;
        $ctx->tradeLabel = $attrs['tradeLabel'] ?? null;
        $ctx->revenueLabel = $attrs['revenueLabel'] ?? null;

        return $ctx;
    }

    private function tierCollection(array $rows): Collection
    {
        return collect($rows)
            ->map(fn ($row) => new ScoreConfiguration($row))
            ->groupBy(fn ($row) => $row->factor_slug);
    }

    /**
     * Passing $delayDays = 0 explicitly (rather than the default -1) bypasses
     * the lazy ScoreConfigurationCache::getNextPlayDelayDays() DB lookup
     * entirely, keeping this a DB-free unit test.
     */
    public function test_disqualifies_when_fico_is_below_the_play_minimum(): void
    {
        $ctx = $this->makeContext(['ficoScore' => 600]);
        $play = new CapitalAccess(['minimum_fico_requirement' => 650, 'allow_recent_late_payment' => true]);
        $borrower = Borrower::factory()->make();

        $result = $this->service->calculate($borrower, $play, $ctx, collect(), collect(), true, 0);

        $this->assertSame(0, $result['probability']);
        $this->assertFalse($result['status']);
        $this->assertSame('FICO_BELOW_MINIMUM', $result['reason_code']);
    }

    public function test_disqualifies_when_utilization_exceeds_the_play_maximum(): void
    {
        $ctx = $this->makeContext(['revolvingUtilization' => 90.0]);
        $play = new CapitalAccess(['maximum_utilization_threshold' => 50, 'allow_recent_late_payment' => true]);
        $borrower = Borrower::factory()->make();

        $result = $this->service->calculate($borrower, $play, $ctx, collect(), collect(), true, 0);

        $this->assertSame(0, $result['probability']);
        $this->assertFalse($result['status']);
        $this->assertSame('UTILIZATION_ABOVE_MAXIMUM', $result['reason_code']);
    }

    public function test_disqualifies_when_borrower_tier_is_below_minimum(): void
    {
        $ctx = $this->makeContext(['currentTier' => 1]);
        $play = new CapitalAccess(['allow_recent_late_payment' => true]);
        $borrower = Borrower::factory()->make();

        $result = $this->service->calculate($borrower, $play, $ctx, collect(), collect(), true, 0);

        $this->assertFalse($result['status']);
        $this->assertSame('TIER_BELOW_MINIMUM', $result['reason_code']);
    }

    public function test_computes_probability_from_readiness_score_plus_difficulty_and_tier_points(): void
    {
        $ctx = $this->makeContext([
            'ficoScore' => 720,
            'revolvingUtilization' => 10.0,
            'readinessScore' => 40,
            'currentTier' => 2,
        ]);
        $play = new CapitalAccess([
            'minimum_fico_requirement' => 650,
            'maximum_utilization_threshold' => 80,
            'difficulty_modifier' => 5,
            'allow_recent_late_payment' => true,
        ]);
        $borrower = Borrower::factory()->make();

        $tiers = $this->tierCollection([
            ['category' => 'subscore_tier', 'model_slug' => 'approval_probability', 'factor_slug' => 'fico_above_minimum', 'min_value' => 0, 'max_value' => null, 'numeric_value' => 10],
            ['category' => 'subscore_tier', 'model_slug' => 'approval_probability', 'factor_slug' => 'utilization', 'min_value' => 0, 'max_value' => 20, 'numeric_value' => 20],
        ]);

        $result = $this->service->calculate($borrower, $play, $ctx, $tiers, collect(), true, 0);

        // base = 40 + 5 = 45; + 10 (fico) + 20 (utilization) = 75
        $this->assertSame(75, $result['probability']);
        $this->assertTrue($result['status']);
        $this->assertNull($result['reason_code']);
    }

    public function test_clamps_the_final_probability_between_zero_and_ninety_nine(): void
    {
        $ctx = $this->makeContext(['readinessScore' => 100, 'currentTier' => 2]);
        $play = new CapitalAccess(['difficulty_modifier' => 50, 'allow_recent_late_payment' => true]);
        $borrower = Borrower::factory()->make();

        $result = $this->service->calculate($borrower, $play, $ctx, collect(), collect(), true, 0);

        $this->assertSame(99, $result['probability']);
    }

    public function test_next_play_delay_is_active_when_borrower_recently_completed_another_play_and_has_not_started_this_one(): void
    {
        $ctx = $this->makeContext(['readinessScore' => 50, 'currentTier' => 2]);
        $play = CapitalAccess::create(['name' => 'Test Play', 'allow_recent_late_payment' => true]);
        $otherPlay = CapitalAccess::create(['name' => 'Other Play']);
        $borrower = Borrower::factory()->create();

        $latestCompletedPlay = BorrowerCapitalAccessPlay::create([
            'borrower_id' => $borrower->id,
            'capital_access_id' => $otherPlay->id, // a different play than the one being scored
            'status' => BorrowerCapitalAccessPlay::STATUS_COMPLETED,
            'updated_at' => now(),
        ]);

        // skipDelayCheck = false, delayDays = 5 (pre-loaded, no DB lookup)
        $result = $this->service->calculate($borrower, $play, $ctx, collect(), collect(), false, 5, $latestCompletedPlay);

        $this->assertTrue($result['status']);
        $this->assertSame('NEXT_PLAY_DELAY_ACTIVE', $result['reason_code']);
        $this->assertNotNull($result['unlock_at']);
    }

    public function test_next_play_delay_is_not_applied_when_delay_days_is_zero(): void
    {
        $ctx = $this->makeContext(['readinessScore' => 50, 'currentTier' => 2]);
        $play = CapitalAccess::create(['name' => 'Test Play', 'allow_recent_late_payment' => true]);
        $borrower = Borrower::factory()->create();

        $result = $this->service->calculate($borrower, $play, $ctx, collect(), collect(), false, 0);

        $this->assertTrue($result['status']);
        $this->assertNull($result['reason_code']);
        $this->assertNull($result['unlock_at']);
    }
}
