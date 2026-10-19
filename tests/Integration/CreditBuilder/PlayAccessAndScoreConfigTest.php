<?php

namespace Tests\Integration\CreditBuilder;

use App\Models\Common\ScoreConfiguration;
use App\Models\CreditBuilder\BorrowerCapitalAccessPlay;
use App\Models\CreditBuilder\CapitalAccess;
use App\Services\Borrower\CapitalAccess\PlayAccessGuard;
use App\Services\Borrower\CapitalAccess\ReadinessScoreCalculationService;
use App\Services\Borrower\CapitalAccess\ScoreConfigurationCache;
use Carbon\Carbon;
use Database\Seeders\ScoreConfigurationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The scoring model as the services read it (ScoreConfigurationCache), the
 * readiness band a total lands in, and which play a borrower may open
 * (PlayAccessGuard).
 */
#[CoversClass(ScoreConfigurationCache::class)]
#[CoversClass(PlayAccessGuard::class)]
#[CoversClass(ReadinessScoreCalculationService::class)]
class PlayAccessAndScoreConfigTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 12:00:00');
        $this->seed(ScoreConfigurationsSeeder::class);
        $this->makeHostPartner();
        // The guard memoises per process; each test starts clean.
        foreach (['runningPlay', 'isDelayActive'] as $memo) {
            (new ReflectionClass(PlayAccessGuard::class))->setStaticPropertyValue($memo, []);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function cache(): ScoreConfigurationCache
    {
        $cache = app(ScoreConfigurationCache::class);
        $cache->flush();

        return $cache;
    }

    #[Test]
    public function the_cache_serves_each_part_of_the_model(): void
    {
        $cache = $this->cache();

        $weights = $cache->getWeightsFor(ScoreConfiguration::MODEL_FUNDING_READINESS);
        $this->assertEquals(35, $weights->get(ScoreConfiguration::FACTOR_FICO)->numeric_value);
        $this->assertCount(8, $cache->getTiersFor(ScoreConfiguration::MODEL_FUNDING_READINESS)->get(ScoreConfiguration::FACTOR_FICO));
        $this->assertSame(range(1, 5), $cache->getReadinessBands()->pluck('sort_order')->map(fn ($o) => (int) $o)->all());
    }

    #[Test]
    public function the_next_play_delay_is_zero_until_configured_and_a_flush_rereads_it(): void
    {
        ScoreConfiguration::where('factor_slug', ScoreConfiguration::FACTOR_NEXT_PLAY_DELAY)->delete();
        $cache = $this->cache();
        $this->assertSame(0, $cache->getNextPlayDelayDays());

        ScoreConfiguration::create([
            'category' => ScoreConfiguration::CATEGORY_TIME,
            'model_slug' => ScoreConfiguration::MODEL_GENERAL_SETTING,
            'factor_slug' => ScoreConfiguration::FACTOR_NEXT_PLAY_DELAY,
            'numeric_value' => 14,
        ]);
        $this->assertSame(0, $cache->getNextPlayDelayDays(), 'served from memory until flushed');

        $cache->flush();
        $this->assertSame(14, $cache->getNextPlayDelayDays());
    }

    /**
     * Defect: the band was the first one, in display order, whose minimum the
     * total reached - correct only while display order runs from the highest
     * minimum down. The score settings save each band's sort_order, so a
     * reordered list (e.g. "Not Ready" first) labelled every borrower with
     * the wrong band.
     */
    #[Test]
    public function the_band_follows_the_score_not_the_display_order(): void
    {
        $order = [ScoreConfiguration::BAND_NOT_READY => 1, ScoreConfiguration::BAND_CONDITIONAL => 2, ScoreConfiguration::BAND_FOUNDATION_READY => 3, ScoreConfiguration::BAND_EXECUTIVE_READY => 4, ScoreConfiguration::BAND_ELITE_READY => 5];
        foreach ($order as $label => $sort) {
            ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_READINESS_BAND)->where('label', $label)->update(['sort_order' => $sort]);
        }
        $this->cache();

        $borrower = $this->makeBorrower($this->makeWhiteLabelPartner(), ['fico_score' => 800, 'business_start_date' => '2018-01-01'])->fresh();
        $result = app(ReadinessScoreCalculationService::class)->calculate($borrower);

        $expected = match (true) {
            $result['total_score'] >= 90 => ScoreConfiguration::BAND_ELITE_READY,
            $result['total_score'] >= 80 => ScoreConfiguration::BAND_EXECUTIVE_READY,
            $result['total_score'] >= 70 => ScoreConfiguration::BAND_FOUNDATION_READY,
            $result['total_score'] >= 60 => ScoreConfiguration::BAND_CONDITIONAL,
            default => ScoreConfiguration::BAND_NOT_READY,
        };
        $this->assertGreaterThanOrEqual(60, $result['total_score'], 'the fixture must score above Not Ready');
        $this->assertSame($expected, $result['score_label']);
    }

    private function play(): CapitalAccess
    {
        return CapitalAccess::create(['name' => 'Play ' . uniqid(), 'status' => CapitalAccess::STATUS_ACTIVE]);
    }

    #[Test]
    public function the_guard_finds_the_borrowers_running_play(): void
    {
        $borrower = $this->makeBorrower($this->makeWhiteLabelPartner());
        $running = BorrowerCapitalAccessPlay::create(['borrower_id' => $borrower->id, 'capital_access_id' => $this->play()->id]);
        BorrowerCapitalAccessPlay::create(['borrower_id' => $this->makeBorrower($this->makeWhiteLabelPartner())->id, 'capital_access_id' => $this->play()->id]);

        $this->assertSame($running->id, PlayAccessGuard::getRunningPlay($borrower->id)?->id);
    }

    #[Test]
    public function the_guard_holds_new_plays_for_the_delay_after_one_is_finished(): void
    {
        ScoreConfiguration::updateOrCreate(
            ['category' => ScoreConfiguration::CATEGORY_TIME, 'model_slug' => ScoreConfiguration::MODEL_GENERAL_SETTING, 'factor_slug' => ScoreConfiguration::FACTOR_NEXT_PLAY_DELAY],
            ['numeric_value' => 30]
        );
        $this->cache();
        $recent = $this->makeBorrower($this->makeWhiteLabelPartner());
        $longAgo = $this->makeBorrower($this->makeWhiteLabelPartner());
        foreach ([[$recent, 5], [$longAgo, 45]] as [$borrower, $daysAgo]) {
            $play = BorrowerCapitalAccessPlay::create(['borrower_id' => $borrower->id, 'capital_access_id' => $this->play()->id, 'status' => BorrowerCapitalAccessPlay::STATUS_COMPLETED]);
            $play->forceFill(['updated_at' => now()->subDays($daysAgo)])->saveQuietly();
        }

        $this->assertTrue(PlayAccessGuard::isDelayActive($recent->id));
        $this->assertFalse(PlayAccessGuard::isDelayActive($longAgo->id));
        $this->assertFalse(PlayAccessGuard::isDelayActive($this->makeBorrower($this->makeWhiteLabelPartner())->id), 'nothing finished yet');
    }
}
