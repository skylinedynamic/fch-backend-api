<?php

namespace Tests\Feature\CreditBuilder;

use App\Http\Controllers\Api\V1\Borrower\CapitalAccess\CapitalAccessOverviewController;
use App\Http\Controllers\Api\V1\Borrower\CapitalAccess\CapitalAccessStrategyController;
use App\Http\Controllers\Api\V1\Borrower\CreditBuilder\CreditAccountController;
use App\Http\Controllers\Api\V1\Borrower\CreditBuilder\CreditBuilderOverviewController;
use App\Http\Controllers\Api\V1\Borrower\CreditBuilder\CreditBuilderStepGuideController;
use App\Models\CreditBuilder\BorrowerTaskVerificationRequest;
use App\Models\CreditBuilder\CreditAccountApplication;
use App\Models\CreditBuilder\Tier;
use App\Models\CreditBuilder\TierTask;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * The borrower portal's Credit Builder and Capital Access pages:
 * /borrower/credit-builder/* and /borrower/capital-access/*. They work on the
 * signed-in borrower's own progress; the ids in their URLs name catalogue
 * entries (strategies, credit accounts), not another borrower's records.
 */
#[CoversClass(CapitalAccessStrategyController::class)]
#[CoversClass(CapitalAccessOverviewController::class)]
#[CoversClass(CreditAccountController::class)]
#[CoversClass(CreditBuilderOverviewController::class)]
#[CoversClass(CreditBuilderStepGuideController::class)]
class BorrowerCreditBuilderApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private Partner $partner;
    private User $me;
    private int $strategyId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->makeHostPartner();
        $this->partner = $this->makeCertifiedPartner();
        $this->me = $this->makeBorrowerUser($this->partner);
        $this->strategyId = DB::table('capital_accesses')->insertGetId([
            'name' => 'Starter Stack',
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function borrowerPages(): array
    {
        return [
            'credit builder progress' => ['GET', '/api/v1/borrower/credit-builder/my-progress'],
            'task overview' => ['GET', '/api/v1/borrower/credit-builder/task-overview'],
            'tier task list' => ['GET', '/api/v1/borrower/credit-builder/tier-wise-tasklist'],
            'credit accounts' => ['POST', '/api/v1/borrower/credit-builder/credit-accounts'],
            'credit account filters' => ['GET', '/api/v1/borrower/credit-builder/credit-accounts/filter-options'],
            'capital access overview' => ['GET', '/api/v1/borrower/capital-access/overview'],
            'strategies' => ['GET', '/api/v1/borrower/capital-access/strategies'],
            'strategy picker' => ['GET', '/api/v1/borrower/capital-access/strategies-list'],
        ];
    }

    #[Test]
    #[DataProvider('borrowerPages')]
    public function each_page_loads_for_the_signed_in_borrower(string $method, string $url): void
    {
        $this->actingAsUser($this->me);

        $this->json($method, $url)->assertOk();
    }

    #[Test]
    #[DataProvider('borrowerPages')]
    public function each_page_is_for_borrowers_only(string $method, string $url): void
    {
        $this->json($method, $url)->assertUnauthorized();

        $this->actingAsUser($this->makePartnerAdmin($this->partner));
        $this->json($method, $url)->assertForbidden();
    }

    #[Test]
    public function the_overview_gives_the_estimated_access_in_full_and_compact(): void
    {
        $this->actingAsUser($this->me);

        $data = $this->getJson('/api/v1/borrower/capital-access/overview')->assertOk()->json('data');

        // In full for the Credit Card Stacking page; compact, as the dashboard's
        // top cards show amounts ($4.53M), for the dashboard hero.
        $this->assertSame(currencyFormat(0), $data['estimated_total_access']);
        $this->assertSame(formatCompactCurrency(0), $data['estimated_total_access_compact']);
        $this->assertSame('$0', $data['estimated_total_access_compact']);
    }

    #[Test]
    public function a_strategy_opens_in_each_of_its_views(): void
    {
        $this->actingAsUser($this->me);

        foreach (['', '/overview', '/checklist', '/roadmap'] as $view) {
            $this->getJson("/api/v1/borrower/capital-access/strategies/{$this->strategyId}{$view}")->assertOk();
        }
    }

    #[Test]
    public function a_retired_or_unknown_strategy_is_not_offered(): void
    {
        DB::table('capital_accesses')->where('id', $this->strategyId)->update(['status' => 'Inactive']);
        $this->actingAsUser($this->me);

        $this->getJson("/api/v1/borrower/capital-access/strategies/{$this->strategyId}")->assertNotFound();
        $this->getJson('/api/v1/borrower/capital-access/strategies/999999')->assertNotFound();
    }

    #[Test]
    public function starting_a_strategy_records_a_running_play_for_me(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson("/api/v1/borrower/capital-access/strategies/{$this->strategyId}/start")->assertSuccessful();

        $this->assertDatabaseHas('borrower_capital_access_plays', [
            'borrower_id' => $this->me->entity_id,
            'capital_access_id' => $this->strategyId,
        ]);
    }

    #[Test]
    public function a_task_verification_request_names_existing_tasks_and_a_tier(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/credit-builder/task-verify-request', ['task_ids' => [999999], 'tier_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['task_ids.0', 'tier_id']);
    }

    /**
     * BUG-034: a double-click on "Submit for Verification" sent two requests and
     * the host got two "processing" rows for one task. A task already in review
     * answers with that request instead of opening a second one; once it is
     * rejected the borrower can submit it again.
     */
    #[Test]
    public function a_task_already_in_review_is_not_submitted_a_second_time(): void
    {
        $tier = Tier::create(['name' => 'Tier 1', 'title' => 'Starter', 'code' => 'T1', 'order' => 1]);
        $task = TierTask::create(['tier_id' => $tier->id, 'title' => 'Uline', 'order' => 1]);
        $this->actingAsUser($this->me);
        $submit = fn () => $this->postJson('/api/v1/borrower/credit-builder/task-verify-request', ['task_ids' => [$task->id], 'tier_id' => $tier->id]);
        $inReview = fn () => BorrowerTaskVerificationRequest::where('task_id', $task->id)
            ->where('status', BorrowerTaskVerificationRequest::PROCESSING)->count();

        $first = $submit()->assertOk()->json('data.0.id');
        $second = $submit()->assertOk()->json('data.0.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, $inReview());

        BorrowerTaskVerificationRequest::whereKey($first)->update(['status' => BorrowerTaskVerificationRequest::REJECTED]);
        $this->assertNotSame($first, $submit()->assertOk()->json('data.0.id'));
        $this->assertSame(1, $inReview());
    }

    #[Test]
    public function a_step_of_another_strategy_cannot_be_submitted_through_this_one(): void
    {
        $other = DB::table('capital_accesses')->insertGetId(['name' => 'Other Stack', 'status' => 'Active', 'created_at' => now(), 'updated_at' => now()]);
        $account = DB::table('credit_accounts')->insertGetId(['card_name' => 'Card', 'created_at' => now(), 'updated_at' => now()]);
        $foreignStep = DB::table('capital_access_sequences')->insertGetId([
            'capital_access_id' => $other,
            'credit_account_id' => $account,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsUser($this->me);

        $this->postJson("/api/v1/borrower/capital-access/strategies/{$this->strategyId}/step/{$foreignStep}/submit", ['status' => 'Pending'])
            ->assertForbidden();
        $this->postJson("/api/v1/borrower/capital-access/strategies/999999/step/{$foreignStep}/submit", ['status' => 'Pending'])
            ->assertNotFound();
        $this->assertDatabaseCount('capital_access_applications', 0);
    }

    /**
     * The status is the borrower's own record of their application with the
     * vendor (the screen lets them move it Requested -> Processing ->
     * Approved), so it is theirs to set; anything else falls back to Requested.
     */
    #[Test]
    public function a_borrower_tracks_their_application_to_a_credit_account(): void
    {
        // An account on offer to this borrower: no FICO or time-in-business gate.
        $account = DB::table('credit_accounts')->insertGetId(['card_name' => 'Net-30 Card', 'issuer' => 'Vendor', 'typical_personal_fico_min' => 0, 'time_in_business_min_months' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsUser($this->me);

        $this->postJson("/api/v1/borrower/credit-builder/credit-accounts/{$account}/apply")->assertOk()->assertJsonPath('data.status', 'Requested');
        $this->postJson("/api/v1/borrower/credit-builder/credit-accounts/{$account}/apply", ['status' => 'Approved'])->assertOk();
        $this->postJson("/api/v1/borrower/credit-builder/credit-accounts/{$account}/apply", ['status' => 'Paid out'])->assertOk()->assertJsonPath('data.status', 'Requested');

        $this->assertSame(1, DB::table('credit_account_applications')->where('borrower_id', $this->me->entity_id)->count());
    }

    /**
     * BUG-028 (R2-CP-CBA-01) - by design, not a defect. QA expected
     * Requested -> Processing -> Approved to be enforced. The vendor approves
     * the account outside the portal; the status is only the borrower's own
     * note of where that stands, so any status may follow any other - N/A
     * straight to Approved, and back again. Keep it that way.
     */
    #[Test]
    public function a_borrower_may_mark_any_status_from_any_other_by_design(): void
    {
        $account = DB::table('credit_accounts')->insertGetId(['card_name' => 'Net-30 Card', 'issuer' => 'Vendor', 'typical_personal_fico_min' => 0, 'time_in_business_min_months' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsUser($this->me);
        $url = "/api/v1/borrower/credit-builder/credit-accounts/{$account}/apply";

        // N/A (no application yet) straight to Approved.
        $this->postJson($url, ['status' => CreditAccountApplication::STATUS_APPROVED])
            ->assertOk()
            ->assertJsonPath('data.status', CreditAccountApplication::STATUS_APPROVED);

        // And back from Approved to Requested.
        $this->postJson($url, ['status' => CreditAccountApplication::STATUS_REQUESTED])
            ->assertOk()
            ->assertJsonPath('data.status', CreditAccountApplication::STATUS_REQUESTED);
    }

    /**
     * Defect: an unknown account id went straight into the insert and failed
     * on the foreign key - a 500 rather than a 404.
     */
    #[Test]
    public function an_unknown_credit_account_is_not_found(): void
    {
        $this->actingAsUser($this->me);

        $this->postJson('/api/v1/borrower/credit-builder/credit-accounts/999999/apply')->assertNotFound();
        $this->assertDatabaseCount('credit_account_applications', 0);
    }

    /**
     * Defect: the host switches a credit account off from the admin list
     * (Active / Inactive), but the borrower list ignored status entirely, so
     * an inactive account was still shown and could still be applied to.
     */
    #[Test]
    public function an_inactive_credit_account_is_not_offered(): void
    {
        $active = DB::table('credit_accounts')->insertGetId(['card_name' => 'Live', 'status' => 'Active', 'typical_personal_fico_min' => 0, 'time_in_business_min_months' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $inactive = DB::table('credit_accounts')->insertGetId(['card_name' => 'Off', 'status' => 'Inactive', 'typical_personal_fico_min' => 0, 'time_in_business_min_months' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsUser($this->me);

        $ids = collect($this->postJson('/api/v1/borrower/credit-builder/credit-accounts')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($active, $ids);
        $this->assertNotContains($inactive, $ids);

        $this->postJson("/api/v1/borrower/credit-builder/credit-accounts/{$inactive}/apply")->assertNotFound();
    }

    /**
     * Defect (O27): the list only offers accounts the borrower qualifies for
     * (minimum FICO, time in business), but apply re-checked none of it, so an
     * application could be recorded against an account the borrower is never
     * shown.
     */
    #[Test]
    public function an_account_the_borrower_does_not_qualify_for_cannot_be_applied_to(): void
    {
        \App\Models\Borrower\Borrower::whereKey($this->me->entity_id)->update([
            'fico_score' => 640,
            'business_start_date' => now()->subMonths(6)->toDateString(),
        ]);
        $account = fn (array $gates) => DB::table('credit_accounts')->insertGetId(array_merge([
            'card_name' => 'Card', 'status' => 'Active', 'typical_personal_fico_min' => 0, 'time_in_business_min_months' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $gates));
        $ficoGated = $account(['typical_personal_fico_min' => 700]);
        $ageGated = $account(['time_in_business_min_months' => 24]);
        $open = $account([]);
        $this->actingAsUser($this->me);

        $this->postJson("/api/v1/borrower/credit-builder/credit-accounts/{$ficoGated}/apply")->assertNotFound();
        $this->postJson("/api/v1/borrower/credit-builder/credit-accounts/{$ageGated}/apply")->assertNotFound();
        $this->postJson("/api/v1/borrower/credit-builder/credit-accounts/{$open}/apply")->assertOk();

        $this->assertSame([$open], DB::table('credit_account_applications')->pluck('credit_account_id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * Defect (BUG-052, R2-OP-CACC-02): the borrower requested a card, then the
     * host raised its FICO minimum above the borrower's score. The card left
     * their list, and apply answered 404, so they could not see or move their
     * own request to Processing / Approved. A card they already applied for
     * stays theirs; borrowers who have not applied still are not offered it.
     */
    #[Test]
    public function a_card_already_requested_stays_with_the_borrower_after_its_minimums_rise(): void
    {
        \App\Models\Borrower\Borrower::whereKey($this->me->entity_id)->update([
            'fico_score' => 760,
            'business_start_date' => now()->subMonths(36)->toDateString(),
        ]);
        $card = DB::table('credit_accounts')->insertGetId([
            'card_name' => 'Business Platinum', 'status' => 'Active', 'typical_personal_fico_min' => 700, 'time_in_business_min_months' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $other = $this->makeBorrowerUser($this->partner);
        \App\Models\Borrower\Borrower::whereKey($other->entity_id)->update(['fico_score' => 760]);
        $listed = fn () => collect($this->postJson('/api/v1/borrower/credit-builder/credit-accounts')->assertOk()->json('data'))->pluck('id')->all();

        $this->actingAsUser($this->me);
        $this->postJson("/api/v1/borrower/credit-builder/credit-accounts/{$card}/apply")->assertOk();

        // The host raises both minimums above this borrower.
        DB::table('credit_accounts')->where('id', $card)->update(['typical_personal_fico_min' => 800, 'time_in_business_min_months' => 120]);

        $this->assertContains($card, $listed());
        $this->postJson("/api/v1/borrower/credit-builder/credit-accounts/{$card}/apply", ['status' => CreditAccountApplication::STATUS_APPROVED])->assertOk();
        $this->assertSame(CreditAccountApplication::STATUS_APPROVED, CreditAccountApplication::where('credit_account_id', $card)->value('status'));

        // A borrower who never applied is no longer offered it.
        $this->actingAsUser($other);
        $this->assertNotContains($card, $listed());
        $this->postJson("/api/v1/borrower/credit-builder/credit-accounts/{$card}/apply")->assertNotFound();

        // Switching the card off still hides it, request or not (BUG-053).
        DB::table('credit_accounts')->where('id', $card)->update(['status' => 'Inactive']);
        $this->actingAsUser($this->me);
        $this->assertNotContains($card, $listed());
    }

    /**
     * Defect (BUG-138, CP-CBA-05): the list was ordered by difficulty_rank only.
     * Many cards share a rank, and MySQL returns ties in any order, so each
     * page request could order them differently: ~12 cards came back on both
     * page 1 and page 2 and ~12 eligible cards never appeared. The id now
     * breaks ties, so every card is on exactly one page.
     *
     * SQLite happens to return ties in insert order, so the repeat itself
     * cannot be reproduced here; the query's ORDER BY is checked directly.
     */
    #[Test]
    public function every_offered_card_is_on_exactly_one_page_in_a_fixed_order(): void
    {
        $ids = [];
        // 20 cards (more than one page of 16), two ranks, several per rank.
        foreach (range(1, 20) as $i) {
            $ids[] = DB::table('credit_accounts')->insertGetId([
                'card_name' => "Card {$i}", 'issuer' => 'Issuer', 'status' => 'Active',
                'difficulty_rank' => $i % 2 ? 1 : 2,
                'typical_personal_fico_min' => 0, 'time_in_business_min_months' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->actingAsUser($this->me);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'from "credit_accounts"') && str_contains($query->sql, 'order by')) {
                $queries[] = $query->sql;
            }
        });

        $page1 = $this->postJson('/api/v1/borrower/credit-builder/credit-accounts', ['page' => 1])->assertOk()->json('data');
        $page2 = $this->postJson('/api/v1/borrower/credit-builder/credit-accounts', ['page' => 2])->assertOk()->json('data');

        $seen = array_merge(array_column($page1, 'id'), array_column($page2, 'id'));
        $this->assertCount(16, $page1);
        $this->assertCount(20, $seen);
        $this->assertEqualsCanonicalizing($ids, $seen, 'every card exactly once');

        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertStringContainsString('order by "difficulty_rank" asc, "credit_accounts"."id" asc', $sql);
        }
    }
}
