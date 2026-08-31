<?php

namespace Tests\Unit\Services\Borrower\CapitalAccess;

use App\Models\Borrower\Borrower;
use App\Models\Common\ScoreConfiguration;
use App\Models\CreditBuilder\CapitalAccess;
use App\Models\CreditBuilder\CapitalAccessSequence;
use App\Services\Borrower\CapitalAccess\ApprovalOddsService;
use App\Services\Borrower\CapitalAccess\BorrowerContext;
use Illuminate\Support\Collection;
use ReflectionClass;
use Tests\TestCase;

class ApprovalOddsServiceTest extends TestCase
{
    protected ApprovalOddsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ApprovalOddsService;
    }

    /**
     * BorrowerContext::from() is the only public constructor path and it
     * pulls readiness data from the DB via ReadinessScoreCalculationService.
     * For a pure/isolated unit test of ApprovalOddsService's own arithmetic,
     * build the context directly via reflection (its properties are public,
     * only the constructor is private) and inject it — this makes the whole
     * test DB-free.
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
        // Group by factor_slug, mirroring ScoreConfigurationCache::getTiersFor()
        return collect($rows)
            ->map(fn ($row) => new ScoreConfiguration($row))
            ->groupBy(fn ($row) => $row->factor_slug);
    }

    public function test_disqualifies_when_fico_is_below_the_sequence_minimum(): void
    {
        $ctx = $this->makeContext(['ficoScore' => 600]);
        $seq = new CapitalAccessSequence(['minimum_fico_requirement' => 650]);
        $play = new CapitalAccess(['id' => 1]);
        $borrower = new Borrower;

        $result = $this->service->calculate($borrower, $seq, $play, $ctx, collect());

        $this->assertSame(0, $result['probability']);
        $this->assertFalse($result['status']);
        $this->assertSame('FICO_BELOW_MINIMUM', $result['reason_code']);
    }

    public function test_disqualifies_when_utilization_exceeds_the_sequence_maximum(): void
    {
        $ctx = $this->makeContext(['revolvingUtilization' => 90.0]);
        $seq = new CapitalAccessSequence(['maximum_utilization_threshold' => 50]);
        $play = new CapitalAccess(['id' => 1]);
        $borrower = new Borrower;

        $result = $this->service->calculate($borrower, $seq, $play, $ctx, collect());

        $this->assertSame(0, $result['probability']);
        $this->assertFalse($result['status']);
        $this->assertSame('UTILIZATION_ABOVE_MAXIMUM', $result['reason_code']);
    }

    public function test_computes_odds_from_readiness_score_plus_difficulty_modifier_and_tier_points(): void
    {
        $ctx = $this->makeContext([
            'ficoScore' => 720,
            'revolvingUtilization' => 10.0,
            'recentInquiries' => 0,
            'readinessScore' => 50,
        ]);
        $seq = new CapitalAccessSequence([
            'minimum_fico_requirement' => 650,
            'maximum_utilization_threshold' => 80,
            'difficulty_modifier' => 5,
        ]);
        $play = new CapitalAccess(['id' => 1]);
        $borrower = new Borrower;

        $tiers = $this->tierCollection([
            ['category' => 'subscore_tier', 'model_slug' => 'approval_odds', 'factor_slug' => 'fico_above_minimum', 'min_value' => 0, 'max_value' => null, 'numeric_value' => 10],
            ['category' => 'subscore_tier', 'model_slug' => 'approval_odds', 'factor_slug' => 'utilization', 'min_value' => 0, 'max_value' => 20, 'numeric_value' => 15],
        ]);

        $result = $this->service->calculate($borrower, $seq, $play, $ctx, $tiers);

        // base = 50 (readiness) + 5 (difficulty) = 55
        // + 10 (fico_above_minimum tier) + 15 (utilization tier) = 80
        $this->assertSame(80, $result['probability']);
        $this->assertTrue($result['status']);
        $this->assertNull($result['reason_code']);
    }

    public function test_clamps_the_final_odds_between_zero_and_ninety_nine(): void
    {
        $ctx = $this->makeContext(['readinessScore' => 100]);
        $seq = new CapitalAccessSequence(['difficulty_modifier' => 50]);
        $play = new CapitalAccess(['id' => 1]);
        $borrower = new Borrower;

        $result = $this->service->calculate($borrower, $seq, $play, $ctx, collect());

        $this->assertSame(99, $result['probability']);
    }

    public function test_banking_tradeline_and_revenue_labels_are_mapped_to_odds_tier_points(): void
    {
        $ctx = $this->makeContext([
            'readinessScore' => 0,
            'bankingLabel' => ScoreConfiguration::STATUS_BANKING_STRONG,
            'tradeLabel' => ScoreConfiguration::STATUS_TRADE_GOOD_DEPTH,
            'revenueLabel' => ScoreConfiguration::STATUS_REVENUE_STRONG,
        ]);
        $seq = new CapitalAccessSequence([]);
        $play = new CapitalAccess(['id' => 1]);
        $borrower = new Borrower;

        $tiers = $this->tierCollection([
            ['category' => 'subscore_tier', 'model_slug' => 'approval_odds', 'factor_slug' => 'banking_relationship', 'label' => ScoreConfiguration::PROB_STATUS_BANKING_STRONG, 'numeric_value' => 5],
            ['category' => 'subscore_tier', 'model_slug' => 'approval_odds', 'factor_slug' => 'tradeline_depth', 'label' => ScoreConfiguration::PROB_STATUS_TRADE_GOOD_DEPTH, 'numeric_value' => 6],
            ['category' => 'subscore_tier', 'model_slug' => 'approval_odds', 'factor_slug' => 'revenue_income', 'label' => ScoreConfiguration::PROB_STATUS_REVENUE_STRONG, 'numeric_value' => 7],
        ]);

        $result = $this->service->calculate($borrower, $seq, $play, $ctx, $tiers);

        // 0 base + 5 + 6 + 7 = 18
        $this->assertSame(18, $result['probability']);
    }
}
