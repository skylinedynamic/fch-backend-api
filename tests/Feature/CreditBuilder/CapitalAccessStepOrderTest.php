<?php

namespace Tests\Feature\CreditBuilder;

use App\Http\Controllers\Api\V1\Borrower\CapitalAccess\CapitalAccessStrategyController;
use App\Models\CreditBuilder\BorrowerCapitalAccessPlay;
use App\Models\CreditBuilder\CapitalAccess;
use App\Models\CreditBuilder\CapitalAccessApplication;
use App\Models\User\User;
use Carbon\Carbon;
use Database\Seeders\ScoreConfigurationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * Working through a capital-access play: its steps open in order (each one
 * after the step before it is approved), a step the borrower does not
 * qualify for stays shut, and approving the last step completes the play.
 */
#[CoversClass(CapitalAccessStrategyController::class)]
class CapitalAccessStepOrderTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private User $me;
    private int $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Carbon::setTestNow('2026-06-15 12:00:00');
        $this->seed(ScoreConfigurationsSeeder::class);
        $this->makeHostPartner();
        $this->me = $this->makeBorrowerUser($this->makeWhiteLabelPartner(), [
            'fico_score' => 740,
            'current_tier' => 3,
            'business_start_date' => '2021-01-01',
        ]);
        $this->strategy = CapitalAccess::create(['name' => 'Starter Stack', 'status' => CapitalAccess::STATUS_ACTIVE, 'minimum_fico_requirement' => 600, 'allow_recent_late_payment' => true])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function step(int $day, array $attributes = []): int
    {
        return DB::table('capital_access_sequences')->insertGetId(array_merge([
            'capital_access_id' => $this->strategy,
            'credit_account_id' => DB::table('credit_accounts')->insertGetId(['card_name' => "Card {$day}", 'created_at' => now(), 'updated_at' => now()]),
            // Open at any hour: this is about step order, not StepTimeLock's
            // Day N / time-of-day windows (StepTimeLockTest covers those).
            'access_time' => CapitalAccess::ACCESS_TIME_ANYTIME,
            'access_day' => $day,
            'access_day_time' => CapitalAccess::ACCESS_TIME_ANYTIME,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function submit(int $step, array $data)
    {
        return $this->postJson("/api/v1/borrower/capital-access/strategies/{$this->strategy}/step/{$step}/submit", $data);
    }

    #[Test]
    public function the_steps_open_in_order_and_the_last_approval_completes_the_play(): void
    {
        $first = $this->step(1);
        $second = $this->step(2);
        $this->actingAsUser($this->me);

        $this->submit($second, ['status' => CapitalAccessApplication::STATUS_PENDING])->assertForbidden();

        $this->submit($first, ['status' => CapitalAccessApplication::STATUS_APPROVED, 'credit_limit' => 5000])->assertOk();
        $this->assertSame(BorrowerCapitalAccessPlay::STATUS_RUNNING, BorrowerCapitalAccessPlay::where('borrower_id', $this->me->entity_id)->value('status'));

        $this->submit($second, ['status' => CapitalAccessApplication::STATUS_APPROVED, 'credit_limit' => 7500])->assertOk();
        $this->assertSame(BorrowerCapitalAccessPlay::STATUS_COMPLETED, BorrowerCapitalAccessPlay::where('borrower_id', $this->me->entity_id)->value('status'));
    }

    #[Test]
    public function a_pending_or_declined_step_keeps_the_next_one_shut(): void
    {
        $first = $this->step(1);
        $second = $this->step(2);
        $this->actingAsUser($this->me);

        $this->submit($first, ['status' => CapitalAccessApplication::STATUS_DECLINED])->assertOk();

        $this->submit($second, ['status' => CapitalAccessApplication::STATUS_PENDING])->assertForbidden();
    }

    #[Test]
    public function an_approval_records_the_credit_limit(): void
    {
        $first = $this->step(1);
        $this->actingAsUser($this->me);

        $this->submit($first, ['status' => CapitalAccessApplication::STATUS_APPROVED])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credit_limit');
        $this->submit($first, ['status' => 'Maybe'])->assertUnprocessable();
    }

    #[Test]
    public function a_step_the_borrower_does_not_qualify_for_stays_shut(): void
    {
        $tooHard = $this->step(1, ['minimum_fico_requirement' => 800]);
        $this->actingAsUser($this->me);

        $this->submit($tooHard, ['status' => CapitalAccessApplication::STATUS_PENDING])->assertForbidden();
        $this->assertDatabaseCount('capital_access_applications', 0);
    }
}
