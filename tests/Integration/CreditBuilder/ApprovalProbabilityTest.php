<?php

namespace Tests\Integration\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\BorrowerCapitalAccessPlay;
use App\Models\CreditBuilder\CapitalAccess;
use App\Services\Borrower\CapitalAccess\ApprovalProbabilityService;
use Carbon\Carbon;
use Database\Seeders\ScoreConfigurationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The approval probability shown on each capital-access play: hard
 * disqualifiers first, then the readiness score plus the play's difficulty
 * and each factor's points, held to 0-99, and the wait between plays.
 */
#[CoversClass(ApprovalProbabilityService::class)]
class ApprovalProbabilityTest extends TestCase
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
        return $this->makeBorrower($this->makeWhiteLabelPartner(), array_merge([
            'fico_score' => 720,
            'current_tier' => 3,
            'business_start_date' => '2022-01-01',
        ], $attributes))->fresh();
    }

    private function play(array $attributes = []): CapitalAccess
    {
        return CapitalAccess::create(array_merge([
            'name' => 'Starter ' . uniqid(),
            'status' => CapitalAccess::STATUS_ACTIVE,
            'minimum_fico_requirement' => 650,
            'maximum_utilization_threshold' => 50,
            'difficulty_modifier' => 0,
            'allow_recent_late_payment' => true,
        ], $attributes));
    }

    private function probability(Borrower $borrower, CapitalAccess $play, bool $skipDelay = true): array
    {
        return app(ApprovalProbabilityService::class)->calculate($borrower, $play, skipDelayCheck: $skipDelay);
    }

    #[Test]
    public function a_borrower_below_the_plays_fico_minimum_is_disqualified(): void
    {
        $result = $this->probability($this->borrower(['fico_score' => 600]), $this->play(['minimum_fico_requirement' => 650]));

        $this->assertSame(['probability' => 0, 'status' => false, 'reason_code' => 'FICO_BELOW_MINIMUM', 'unlock_at' => null], $result);
    }

    #[Test]
    public function a_borrower_below_tier_two_is_disqualified(): void
    {
        $this->assertSame('TIER_BELOW_MINIMUM', $this->probability($this->borrower(['current_tier' => 1]), $this->play())['reason_code']);
    }

    #[Test]
    public function a_qualifying_borrower_gets_a_probability(): void
    {
        $result = $this->probability($this->borrower(), $this->play());

        $this->assertTrue($result['status']);
        $this->assertNull($result['reason_code']);
        $this->assertGreaterThan(0, $result['probability']);
    }

    #[Test]
    public function the_plays_difficulty_moves_the_probability_point_for_point(): void
    {
        $borrower = $this->borrower();

        $base = $this->probability($borrower, $this->play())['probability'];
        $harder = $this->probability($borrower, $this->play(['difficulty_modifier' => -10]))['probability'];

        $this->assertSame($base - 10, $harder);
    }

    #[Test]
    public function the_probability_is_held_between_0_and_99(): void
    {
        $borrower = $this->borrower();

        $this->assertSame(99, $this->probability($borrower, $this->play(['difficulty_modifier' => 500]))['probability']);
        $this->assertSame(0, $this->probability($borrower, $this->play(['difficulty_modifier' => -500]))['probability']);
    }

    #[Test]
    public function a_recently_finished_play_holds_the_next_one_until_the_delay_passes(): void
    {
        $borrower = $this->borrower();
        $finished = $this->play();
        $next = $this->play();
        $done = BorrowerCapitalAccessPlay::create(['borrower_id' => $borrower->id, 'capital_access_id' => $finished->id, 'status' => BorrowerCapitalAccessPlay::STATUS_COMPLETED]);
        $done->forceFill(['updated_at' => now()->subDays(5)])->saveQuietly();

        $held = app(ApprovalProbabilityService::class)->calculate($borrower, $next, delayDays: 30);
        $this->assertSame('NEXT_PLAY_DELAY_ACTIVE', $held['reason_code']);
        $this->assertSame(now()->subDays(5)->addDays(30)->toIso8601String(), $held['unlock_at']);

        $this->assertNull(app(ApprovalProbabilityService::class)->calculate($borrower, $next, delayDays: 3)['reason_code'], 'the delay has passed');
        $this->assertNull(app(ApprovalProbabilityService::class)->calculate($borrower, $next, skipDelayCheck: true, delayDays: 30)['reason_code']);
    }

    #[Test]
    public function a_play_already_started_is_not_held_by_the_delay(): void
    {
        $borrower = $this->borrower();
        $finished = $this->play();
        $current = $this->play();
        BorrowerCapitalAccessPlay::create(['borrower_id' => $borrower->id, 'capital_access_id' => $finished->id, 'status' => BorrowerCapitalAccessPlay::STATUS_COMPLETED]);
        BorrowerCapitalAccessPlay::create(['borrower_id' => $borrower->id, 'capital_access_id' => $current->id]);

        $this->assertNull(app(ApprovalProbabilityService::class)->calculate($borrower, $current, delayDays: 30)['reason_code']);
    }
}
