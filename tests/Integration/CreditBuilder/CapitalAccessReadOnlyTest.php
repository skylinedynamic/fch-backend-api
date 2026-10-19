<?php

namespace Tests\Integration\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\CreditBuilder\BorrowerCapitalAccessPlay;
use App\Models\CreditBuilder\CapitalAccess;
use App\Models\User\User;
use Carbon\Carbon;
use Database\Seeders\ScoreConfigurationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Listing a borrower's capital access strategies is a read.
 *
 * Defect (O41): resolveRecommended() - run by the strategy list and overview
 * GETs - created borrower_capital_access_plays rows for strategies a borrower
 * had applications on but no play row ("legacy data sync"). Every page view
 * could write. Those rows are now backfilled once by a migration, and the read
 * path works out completion without writing.
 */
class CapitalAccessReadOnlyTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $me;
    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-15 12:00:00');
        $this->seed(ScoreConfigurationsSeeder::class);
        $this->makeHostPartner();
        $this->me = $this->makeBorrowerUser($this->makeWhiteLabelPartner(), ['business_start_date' => '2020-01-01']);
        $this->borrower = Borrower::findOrFail($this->me->entity_id);
        BorrowerCreditProfile::create([
            'borrower_id' => $this->borrower->id,
            'fico_score' => 780,
            'recent_inquiries' => 0,
            'open_accounts_analysis' => ['utilization_ratio_grid' => ['revolving' => 5]],
        ]);
        // Tier 2 and up is where capital access plays unlock.
        $this->borrower->update(['fico_score' => 780, 'current_tier' => 2]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** A strategy with $steps steps, $approved of them approved for the borrower (no play row). */
    private function strategyWorkedOn(int $steps, int $approved): int
    {
        $strategy = CapitalAccess::create(['name' => "Play {$steps}/{$approved}", 'status' => CapitalAccess::STATUS_ACTIVE]);

        for ($i = 0; $i < $steps; $i++) {
            $card = DB::table('credit_accounts')->insertGetId([
                'card_name' => "Card {$i}", 'status' => 'Active', 'typical_personal_fico_min' => 0, 'time_in_business_min_months' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $sequenceId = DB::table('capital_access_sequences')->insertGetId([
                'capital_access_id' => $strategy->id, 'credit_account_id' => $card, 'sorting_order' => $i + 1,
                'minimum_fico_requirement' => 0, 'maximum_utilization_threshold' => 100, 'difficulty_modifier' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('capital_access_applications')->insert([
                'borrower_id' => $this->borrower->id, 'capital_access_id' => $strategy->id,
                'capital_access_sequence_id' => $sequenceId,
                'status' => $i < $approved ? 'Approved' : 'Pending',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $strategy->id;
    }

    #[Test]
    public function listing_the_strategies_writes_no_play_rows(): void
    {
        $this->strategyWorkedOn(2, 1);
        $this->strategyWorkedOn(1, 1);
        $this->actingAsUser($this->me);

        $this->getJson('/api/v1/borrower/capital-access/strategies')->assertOk();
        $this->getJson('/api/v1/borrower/capital-access/overview')->assertOk();

        $this->assertSame(0, BorrowerCapitalAccessPlay::count());
    }

    #[Test]
    public function the_migration_backfills_a_play_row_for_every_strategy_worked_on(): void
    {
        $running = $this->strategyWorkedOn(2, 1);
        $completed = $this->strategyWorkedOn(1, 1);
        $migration = require database_path('migrations/2026_10_12_000100_backfill_borrower_capital_access_plays.php');

        $migration->up();
        $migration->up(); // a second run adds nothing

        $plays = BorrowerCapitalAccessPlay::where('borrower_id', $this->borrower->id)->pluck('status', 'capital_access_id');
        $this->assertCount(2, $plays);
        $this->assertSame(BorrowerCapitalAccessPlay::STATUS_RUNNING, $plays[$running]);
        $this->assertSame(BorrowerCapitalAccessPlay::STATUS_COMPLETED, $plays[$completed]);
    }
}
