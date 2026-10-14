<?php

namespace Tests\Feature\CreditBuilder;

use App\Http\Controllers\Api\V1\Borrower\CapitalAccess\CapitalAccessOverviewController;
use App\Http\Controllers\Api\V1\Borrower\CapitalAccess\CapitalAccessStrategyController;
use App\Http\Controllers\Api\V1\Borrower\CreditBuilder\CreditAccountController;
use App\Http\Controllers\Api\V1\Borrower\CreditBuilder\CreditBuilderOverviewController;
use App\Http\Controllers\Api\V1\Borrower\CreditBuilder\CreditBuilderStepGuideController;
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
}
