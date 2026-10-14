<?php

namespace Tests\Integration\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Services\Borrower\CapitalAccess\BorrowerContext;
use App\Services\Borrower\CapitalAccess\ReadinessScoreCalculationService;
use Carbon\Carbon;
use Database\Seeders\ScoreConfigurationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The Capital Access Readiness score a borrower sees, computed against the
 * shipped scoring model (ScoreConfigurationsSeeder) on a fixed date.
 */
#[CoversClass(ReadinessScoreCalculationService::class)]
#[CoversClass(BorrowerContext::class)]
class ReadinessScoreCalculationTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 12:00:00');
        $this->seed(ScoreConfigurationsSeeder::class);
        $this->makeHostPartner();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function borrower(array $attributes = []): Borrower
    {
        return $this->makeBorrower($this->makeWhiteLabelPartner(), $attributes)->fresh();
    }

    /** @return array<string, mixed> */
    private function factor(Borrower $borrower, string $slug): array
    {
        return collect(app(ReadinessScoreCalculationService::class)->calculate($borrower)['factors'])->firstWhere('slug', $slug);
    }

    public static function ficoScores(): array
    {
        return [
            'top tier' => [800, 100],
            'tier edge' => [760, 95],
            'mid' => [705, 70],
            'just under 660' => [659, 20],
            'none on file' => [null, 20],
        ];
    }

    #[Test]
    #[DataProvider('ficoScores')]
    public function personal_credit_scores_by_the_fico_tiers(?int $fico, float $expected): void
    {
        $this->assertEquals($expected, $this->factor($this->borrower(['fico_score' => $fico]), 'fico')['raw_score']);
    }

    #[Test]
    public function a_pulled_credit_profile_outranks_the_self_reported_fico(): void
    {
        $borrower = $this->borrower(['fico_score' => 600]);
        BorrowerCreditProfile::create(['borrower_id' => $borrower->id, 'fico_score' => 790]);

        $this->assertEquals(100, $this->factor($borrower->fresh(), 'fico')['raw_score']);
    }

    public static function businessAges(): array
    {
        return [
            // 2026-06-15 is "now".
            'exactly three years' => ['2023-06-15', 100, 'Established'],
            'exactly two years' => ['2024-06-15', 85, null],
            'exactly one year' => ['2025-06-15', 70, null],
            'twenty-three and a half months' => ['2024-07-01', 70, null],
            'eleven and a half months' => ['2025-07-01', 50, null],
            'five and a half months' => ['2025-12-31', 25, null],
            'brand new' => ['2026-06-01', 25, null],
        ];
    }

    /**
     * Defect: months came from Carbon 3's fractional diffInMonths(), and the
     * tiers are whole-month bands (…5 | 6-11 | 12-23 | 24-35 | 36…), so a
     * business 5.x, 11.x, 23.x or 35.x months old fell between two bands and
     * scored 0 for the factor while the screen read "23 months+".
     */
    #[Test]
    #[DataProvider('businessAges')]
    public function time_in_business_counts_whole_months(string $start, float $expected, ?string $_): void
    {
        $factor = $this->factor($this->borrower(['business_start_date' => $start]), 'time_in_business');

        $this->assertEquals($expected, $factor['raw_score']);
    }

    #[Test]
    public function a_business_with_no_start_date_is_new(): void
    {
        $factor = $this->factor($this->borrower(['business_start_date' => null]), 'time_in_business');

        $this->assertSame('New business', $factor['value']);
        $this->assertEquals(25, $factor['raw_score']);
    }

    public static function utilizations(): array
    {
        return [
            'very low' => [3.0, 100],
            'band edge' => [10.0, 90],
            'high' => [55.0, 10],
            'between two bands' => [5.004, 100],
            'between two higher bands' => [30.006, 35],
        ];
    }

    /**
     * Includes a defect: the bands are written to two decimals (…5 | 5.01-10 |
     * …), so a ratio such as 5.004 fell in no band and scored 0.
     */
    #[Test]
    #[DataProvider('utilizations')]
    public function utilization_scores_by_its_bands(float $ratio, float $expected): void
    {
        $borrower = $this->borrower();
        BorrowerCreditProfile::create([
            'borrower_id' => $borrower->id,
            'open_accounts_analysis' => ['utilization_ratio_grid' => ['revolving' => $ratio]],
        ]);

        $this->assertEquals($expected, $this->factor($borrower->fresh(), 'utilization')['raw_score']);
    }

    #[Test]
    public function the_total_is_the_weighted_sum_capped_at_100_with_its_band(): void
    {
        $result = app(ReadinessScoreCalculationService::class)->calculate($this->borrower([
            'fico_score' => 800,
            'business_start_date' => '2020-01-01',
        ]));

        $weighted = collect($result['factors'])->sum('weighted_score');
        $this->assertSame((int) min(100, round($weighted)), $result['total_score']);
        $this->assertNotEmpty($result['score_label']);
        $this->assertCount(6, $result['factors']);
    }

    /**
     * Defect: the approval-probability and approval-odds models band
     * utilization the same way (…5 | 5.01-10 | … | 25.01…), and read the raw
     * ratio, so 25.005% earned the neutral 0 points instead of the -10 for
     * 25.01% and over.
     */
    #[Test]
    public function the_approval_models_read_utilization_to_two_decimals(): void
    {
        $borrower = $this->borrower();
        BorrowerCreditProfile::create([
            'borrower_id' => $borrower->id,
            'open_accounts_analysis' => ['utilization_ratio_grid' => ['revolving' => 25.005]],
        ]);

        $this->assertSame(25.01, BorrowerContext::extractRevolvingUtilization($borrower->fresh()));
    }
}
