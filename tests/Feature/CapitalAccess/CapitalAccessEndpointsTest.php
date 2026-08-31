<?php

namespace Tests\Feature\CapitalAccess;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\CapitalAccess;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\TestCase;

class CapitalAccessEndpointsTest extends TestCase
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
        $response = $this->getJson('/api/v1/borrower/capital-access/overview');

        $response->assertStatus(401);
    }

    public function test_overview_succeeds_with_no_configuration_or_active_strategies(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/borrower/capital-access/overview');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.readiness_score', 0)
            ->assertJsonPath('data.reason_code', 'NO_ACTIVE_PLAYS');
    }

    public function test_strategies_list_returns_an_empty_collection_when_none_are_active(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/borrower/capital-access/strategies-list');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data', []);
    }

    public function test_strategies_index_returns_an_empty_collection_when_none_are_active(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/borrower/capital-access/strategies');

        $response->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_show_strategy_returns_404_for_a_nonexistent_id(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/borrower/capital-access/strategies/999999');

        $response->assertStatus(404);
    }

    public function test_show_strategy_succeeds_for_an_active_strategy(): void
    {
        $strategy = CapitalAccess::create(['name' => 'Line of Credit', 'status' => CapitalAccess::STATUS_ACTIVE]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/borrower/capital-access/strategies/'.$strategy->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $strategy->id);
    }

    public function test_start_play_creates_a_running_play_status(): void
    {
        $strategy = CapitalAccess::create(['name' => 'Line of Credit', 'status' => CapitalAccess::STATUS_ACTIVE]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/capital-access/strategies/'.$strategy->id.'/start');

        $response->assertStatus(200)->assertJsonPath('status', true);

        $this->assertDatabaseHas('borrower_capital_access_plays', [
            'borrower_id' => $this->borrower->id,
            'capital_access_id' => $strategy->id,
            'status' => 'running',
        ]);
    }
}
