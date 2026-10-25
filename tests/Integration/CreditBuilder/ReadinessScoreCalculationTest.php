<?php

namespace Tests\Integration\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerBankAccount;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\Borrower\BorrowerCreditTrade;
use App\Models\Common\ScoreConfiguration;
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

    // -- BUG-031: Tradeline Depth -------------------------------------------

    /**
     * Gives the borrower a pulled credit file with these trades.
     *
     * @param  array<int, array{status: string, limit: float|int|null}>  $trades
     */
    private function withTrades(Borrower $borrower, array $trades): Borrower
    {
        $profile = BorrowerCreditProfile::create(['borrower_id' => $borrower->id]);
        foreach ($trades as $i => $trade) {
            BorrowerCreditTrade::create([
                'borrower_credit_profile_id' => $profile->id,
                'company' => "Lender {$i}",
                'status' => $trade['status'],
                'limit' => $trade['limit'],
            ]);
        }

        return $borrower->fresh();
    }

    private static function open(int $count, float $limit): array
    {
        return array_fill(0, $count, ['status' => 'OPEN', 'limit' => $limit]);
    }

    public static function tradelineFiles(): array
    {
        // A high-limit account: an open trade with a limit of $5,000 or more.
        return [
            '5 open, 2 high-limit, $16,000 in limits' => [
                [...self::open(2, 6000), ...self::open(3, 1000)], ScoreConfiguration::STATUS_TRADE_MULTIPLE_HIGH,
            ],
            '3 open, 1 high-limit, $10,000 in limits' => [
                [...self::open(1, 6000), ...self::open(2, 2000)], ScoreConfiguration::STATUS_TRADE_GOOD_DEPTH,
            ],
            '3 open but no high-limit account' => [
                self::open(3, 4000), ScoreConfiguration::STATUS_TRADE_MODERATE,
            ],
            '1 open' => [
                self::open(1, 9000), ScoreConfiguration::STATUS_TRADE_THIN,
            ],
            'closed trades do not count' => [
                [...self::open(1, 6000), ['status' => 'CLOSED', 'limit' => 20000], ['status' => 'CLOSED', 'limit' => 20000]],
                ScoreConfiguration::STATUS_TRADE_THIN,
            ],
        ];
    }

    /**
     * Defect: only open_accounts was read; high_limit_accounts and limit were
     * taken as 0, so the two upper tiers could never match and every file
     * with 2+ open trades read the same.
     */
    #[Test]
    #[DataProvider('tradelineFiles')]
    public function tradeline_depth_reads_open_accounts_high_limit_accounts_and_limits(array $trades, string $expected): void
    {
        $factor = $this->factor($this->withTrades($this->borrower(), $trades), 'tradeline_depth');

        $this->assertSame($expected, $factor['status']);
        $this->assertSame($expected, $factor['value']);
    }

    /** Defect: with no credit file the factor had no value and showed blank. */
    #[Test]
    public function tradeline_depth_says_so_when_there_is_no_credit_file(): void
    {
        $factor = $this->factor($this->borrower(), 'tradeline_depth');

        $this->assertSame('No credit file', $factor['value']);
        $this->assertEquals(0, $factor['raw_score']);
    }

    // -- BUG-031: wording ------------------------------------------------------

    public static function bankAccounts(): array
    {
        // 2026-06-15 is "now".
        return [
            'none' => [[], '/^No bank accounts$/'],
            'one' => [['2021-03-15'], '/^1 account\b(?!s)/'],
            'two' => [['2021-03-15', '2024-06-15'], '/^2 accounts\b/'],
        ];
    }

    /** Defect: "1 accounts, oldest relationship 63 months" and "0 account(s)". */
    #[Test]
    #[DataProvider('bankAccounts')]
    public function banking_relationships_reads_as_a_sentence(array $opened, string $pattern): void
    {
        $borrower = $this->borrower();
        foreach ($opened as $date) {
            BorrowerBankAccount::create(['borrower_id' => $borrower->id, 'bank_name' => 'Bank', 'account_open_date' => $date]);
        }

        $value = $this->factor($borrower->fresh(), 'banking_relationships')['value'];

        $this->assertMatchesRegularExpression($pattern, $value);
        $this->assertStringNotContainsString('(s)', $value);
    }

    public static function businessAgeWording(): array
    {
        return [
            'one month' => ['2026-05-15', '1 month+'],
            'eleven months' => ['2025-07-15', '11 months+'],
        ];
    }

    /** Defect: "1 months+". */
    #[Test]
    #[DataProvider('businessAgeWording')]
    public function time_in_business_reads_as_a_sentence(string $start, string $expected): void
    {
        $this->assertSame($expected, $this->factor($this->borrower(['business_start_date' => $start]), 'time_in_business')['value']);
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
