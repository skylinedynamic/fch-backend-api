<?php

namespace Tests\Integration\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\CreditBuilder\CapitalAccess;
use App\Models\CreditBuilder\CapitalAccessSequence;
use App\Services\Borrower\CapitalAccess\ApprovalOddsService;
use Carbon\Carbon;
use Database\Seeders\ScoreConfigurationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The approval odds on each card of a capital-access roadmap: the card's FICO
 * and utilization gates, then the readiness score plus the card's difficulty
 * and each factor's points, held to 0-99.
 */
#[CoversClass(ApprovalOddsService::class)]
class ApprovalOddsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private CapitalAccess $play;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 12:00:00');
        $this->seed(ScoreConfigurationsSeeder::class);
        $this->makeHostPartner();
        $this->play = CapitalAccess::create(['name' => 'Starter', 'status' => CapitalAccess::STATUS_ACTIVE]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A borrower with a pulled profile: FICO, revolving utilization and recent inquiries. */
    private function borrower(int $fico = 720, float $utilization = 8.0, int $inquiries = 1): Borrower
    {
        $borrower = $this->makeBorrower($this->makeWhiteLabelPartner(), ['business_start_date' => '2022-01-01']);
        BorrowerCreditProfile::create([
            'borrower_id' => $borrower->id,
            'fico_score' => $fico,
            'recent_inquiries' => $inquiries,
            'open_accounts_analysis' => ['utilization_ratio_grid' => ['revolving' => $utilization]],
        ]);

        return $borrower->fresh();
    }

    private function card(array $attributes = []): CapitalAccessSequence
    {
        return (new CapitalAccessSequence())->forceFill(array_merge([
            'capital_access_id' => $this->play->id,
            'minimum_fico_requirement' => 650,
            'maximum_utilization_threshold' => 30,
            'difficulty_modifier' => 0,
        ], $attributes));
    }

    private function odds(Borrower $borrower, CapitalAccessSequence $card): array
    {
        return app(ApprovalOddsService::class)->calculate($borrower, $card, $this->play);
    }

    /**
     * Defect (O40): every roadmap card wrote 4-5 info-level log lines (base,
     * factor points, final, lock reason) each time a roadmap rendered.
     */
    #[Test]
    public function scoring_a_card_writes_nothing_at_info_level(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        $this->odds($this->borrower(), $this->card());
        $this->odds($this->borrower(fico: 640), $this->card(['minimum_fico_requirement' => 650]));

        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('info');
    }

    #[Test]
    public function a_card_above_the_borrowers_fico_is_locked(): void
    {
        $this->assertSame(
            ['probability' => 0, 'status' => false, 'reason_code' => 'FICO_BELOW_MINIMUM'],
            $this->odds($this->borrower(fico: 640), $this->card(['minimum_fico_requirement' => 650]))
        );
    }

    #[Test]
    public function a_card_below_the_borrowers_utilization_is_locked(): void
    {
        $this->assertSame('UTILIZATION_ABOVE_MAXIMUM', $this->odds($this->borrower(utilization: 45), $this->card(['maximum_utilization_threshold' => 30]))['reason_code']);
    }

    #[Test]
    public function a_card_with_no_gates_is_open_to_anyone(): void
    {
        $result = $this->odds($this->borrower(fico: 500, utilization: 90), $this->card(['minimum_fico_requirement' => null, 'maximum_utilization_threshold' => null]));

        $this->assertTrue($result['status']);
    }

    #[Test]
    public function the_cards_difficulty_moves_the_odds_point_for_point_within_0_to_99(): void
    {
        $borrower = $this->borrower();
        $base = $this->odds($borrower, $this->card())['probability'];

        $this->assertSame($base - 7, $this->odds($borrower, $this->card(['difficulty_modifier' => -7]))['probability']);
        $this->assertSame(99, $this->odds($borrower, $this->card(['difficulty_modifier' => 400]))['probability']);
        $this->assertSame(0, $this->odds($borrower, $this->card(['difficulty_modifier' => -400]))['probability']);
    }

    #[Test]
    public function recent_inquiries_cost_points_by_their_bands(): void
    {
        // 0-2 inquiries: +3, 3-4: 0, 5+: -8.
        $few = $this->odds($this->borrower(inquiries: 1), $this->card())['probability'];
        $some = $this->odds($this->borrower(inquiries: 3), $this->card())['probability'];
        $many = $this->odds($this->borrower(inquiries: 6), $this->card())['probability'];

        $this->assertSame(3, $few - $some);
        $this->assertSame(8, $some - $many);
    }

    #[Test]
    public function lower_utilization_earns_more_points(): void
    {
        // 0-5%: +5, 5.01-10%: +3 (readiness differs too, so only the order is asserted).
        $low = $this->odds($this->borrower(utilization: 3), $this->card())['probability'];
        $higher = $this->odds($this->borrower(utilization: 22), $this->card())['probability'];

        $this->assertGreaterThan($higher, $low);
    }
}
