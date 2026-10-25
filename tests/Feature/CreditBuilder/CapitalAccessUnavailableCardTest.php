<?php

namespace Tests\Feature\CreditBuilder;

use App\Http\Controllers\Api\V1\Borrower\CapitalAccess\CapitalAccessStrategyController;
use App\Models\CreditBuilder\BorrowerCapitalAccessPlay;
use App\Models\CreditBuilder\CapitalAccess;
use App\Models\CreditBuilder\CapitalAccessApplication;
use App\Models\CreditBuilder\CreditAccount;
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
 * BUG-042: a credit account the host inactivates (or deletes) is not offered
 * in any Capital Access play - its step is left out of the play's roadmap,
 * cannot be opened or submitted, and does not count toward completing the
 * play.
 */
#[CoversClass(CapitalAccessStrategyController::class)]
class CapitalAccessUnavailableCardTest extends TestCase
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
        $this->actingAsUser($this->me);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0:int, 1:int} [step id, card id] */
    private function step(int $day, string $cardStatus = CreditAccount::STATUS_ACTIVE): array
    {
        $card = DB::table('credit_accounts')->insertGetId(['card_name' => "Card {$day}", 'status' => $cardStatus, 'created_at' => now(), 'updated_at' => now()]);
        $step = DB::table('capital_access_sequences')->insertGetId([
            'capital_access_id' => $this->strategy,
            'credit_account_id' => $card,
            'access_time' => CapitalAccess::ACCESS_TIME_ANYTIME,
            'access_day' => $day,
            'access_day_time' => CapitalAccess::ACCESS_TIME_ANYTIME,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$step, $card];
    }

    private function submit(int $step, array $data)
    {
        return $this->postJson("/api/v1/borrower/capital-access/strategies/{$this->strategy}/step/{$step}/submit", $data);
    }

    /** @return string[] the card names a play endpoint lists */
    private function listedCards(string $path): array
    {
        return collect($this->getJson("/api/v1/borrower/capital-access/strategies/{$this->strategy}{$path}")->assertOk()->json('data.roadmap'))
            ->map(fn (array $row) => $row['card_name'] ?? $row['card']['card_name'] ?? null)
            ->all();
    }

    #[Test]
    public function an_inactive_card_is_left_out_of_the_play(): void
    {
        $this->step(1);
        $this->step(2, CreditAccount::STATUS_INACTIVE);
        $this->step(3);

        $this->assertSame(['Card 1', 'Card 3'], $this->listedCards(''));
        $this->assertSame(['Card 1', 'Card 3'], $this->listedCards('/roadmap'));
    }

    #[Test]
    public function a_deleted_card_is_left_out_too_never_a_blank_step(): void
    {
        $this->step(1);
        [, $card] = $this->step(2);
        CreditAccount::findOrFail($card)->delete();

        $this->assertSame(['Card 1'], $this->listedCards(''));
        $this->assertSame(['Card 1'], $this->listedCards('/roadmap'));
    }

    #[Test]
    public function an_inactive_cards_step_cannot_be_opened_or_submitted(): void
    {
        $this->step(1);
        [$hidden] = $this->step(2, CreditAccount::STATUS_INACTIVE);

        // Answered like any step that is not part of the play.
        $this->getJson("/api/v1/borrower/capital-access/strategies/{$this->strategy}/step/{$hidden}")
            ->assertForbidden()
            ->assertJsonPath('message', 'This step does not exist for the selected strategy.');
        $this->submit($hidden, ['status' => CapitalAccessApplication::STATUS_PENDING])
            ->assertForbidden()
            ->assertJsonPath('message', 'This step does not exist for the selected strategy.');
        $this->assertSame(0, CapitalAccessApplication::where('capital_access_sequence_id', $hidden)->count());
    }

    #[Test]
    public function the_play_completes_without_the_inactive_cards_step(): void
    {
        [$first] = $this->step(1);
        $this->step(2, CreditAccount::STATUS_INACTIVE);
        [$third] = $this->step(3);

        $this->submit($first, ['status' => CapitalAccessApplication::STATUS_APPROVED, 'credit_limit' => 5000])->assertOk();
        // The next step offered is the one after the inactive card.
        $this->submit($third, ['status' => CapitalAccessApplication::STATUS_APPROVED, 'credit_limit' => 7500])->assertOk();

        $this->assertSame(
            BorrowerCapitalAccessPlay::STATUS_COMPLETED,
            BorrowerCapitalAccessPlay::where('borrower_id', $this->me->entity_id)->value('status')
        );
    }

    #[Test]
    public function reactivating_the_card_brings_its_step_back(): void
    {
        $this->step(1);
        [, $card] = $this->step(2, CreditAccount::STATUS_INACTIVE);

        CreditAccount::findOrFail($card)->update(['status' => CreditAccount::STATUS_ACTIVE]);

        $this->assertSame(['Card 1', 'Card 2'], $this->listedCards('/roadmap'));
    }
}
