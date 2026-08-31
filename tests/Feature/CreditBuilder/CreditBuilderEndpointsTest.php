<?php

namespace Tests\Feature\CreditBuilder;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\Tier;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\TestCase;

class CreditBuilderEndpointsTest extends TestCase
{
    protected Borrower $borrower;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->borrower = Borrower::factory()->create();
        $this->user = User::factory()->borrower($this->borrower)->create();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/borrower/credit-builder/my-progress');

        $response->assertStatus(401);
    }

    public function test_my_progress_succeeds_with_no_tiers_or_tasks_configured(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/borrower/credit-builder/my-progress');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.total_tasks_remaining', 0)
            ->assertJsonPath('data.total_complete_task', 0);
    }

    /**
     * CONFIRMED LIVE BUG (documented, not fixed here): when the borrower has
     * no current_tier, OverviewService::taskOverview() early-returns
     * ['tier' => null, 'tasks' => collect([])] — but TaskOverviewResource
     * unconditionally reads $this->resource['requests'], not 'tasks'. That
     * key-name mismatch throws "Undefined array key requests" for any
     * borrower who has not yet been assigned a tier (a common, not edge-case,
     * state), surfaced here as a 500.
     */
    public function test_task_overview_currently_errors_for_a_borrower_with_no_tier_confirmed_bug(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/borrower/credit-builder/task-overview');

        $response->assertStatus(500);
    }

    public function test_task_overview_succeeds_once_the_borrower_has_a_current_tier(): void
    {
        $tier = Tier::factory()->create();
        $this->borrower->update(['current_tier' => $tier->id]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/borrower/credit-builder/task-overview');

        $response->assertStatus(200)->assertJsonPath('status', true);
    }

    public function test_credit_accounts_filter_options_succeeds(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/borrower/credit-builder/credit-accounts/filter-options');

        $response->assertStatus(200);
    }

    public function test_credit_accounts_index_succeeds_with_no_accounts(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/credit-builder/credit-accounts', []);

        $response->assertStatus(200);
    }
}
