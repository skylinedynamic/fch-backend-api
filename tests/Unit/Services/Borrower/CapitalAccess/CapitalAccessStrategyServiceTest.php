<?php

namespace Tests\Unit\Services\Borrower\CapitalAccess;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\BorrowerCapitalAccessPlay;
use App\Models\CreditBuilder\CapitalAccess;
use App\Models\User\User;
use App\Services\Borrower\CapitalAccess\CapitalAccessStrategyService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\TestCase;

class CapitalAccessStrategyServiceTest extends TestCase
{
    protected CapitalAccessStrategyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->service = new CapitalAccessStrategyService;
    }

    public function test_list_for_selection_returns_an_empty_collection_when_no_active_strategies_exist(): void
    {
        $response = $this->service->listForSelection();

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertTrue($body['status']);
        $this->assertSame([], $body['data']);
    }

    public function test_list_returns_an_empty_collection_when_no_active_strategies_exist(): void
    {
        $response = $this->service->list();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $response->getData(true)['data']);
    }

    public function test_list_only_includes_active_strategies_by_name(): void
    {
        CapitalAccess::create(['name' => 'Active Strategy', 'status' => CapitalAccess::STATUS_ACTIVE]);
        CapitalAccess::create(['name' => 'Inactive Strategy', 'status' => CapitalAccess::STATUS_INACTIVE]);

        $response = $this->service->list();

        $names = collect($response->getData(true)['data'])->pluck('name')->all();

        $this->assertContains('Active Strategy', $names);
        $this->assertNotContains('Inactive Strategy', $names);
    }

    public function test_list_for_borrower_returns_an_empty_collection_when_no_active_strategies_exist(): void
    {
        $borrower = Borrower::factory()->create();

        $response = $this->service->listForBorrower($borrower);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $response->getData(true)['data']);
    }

    public function test_show_returns_the_active_strategy_by_id(): void
    {
        $strategy = CapitalAccess::create(['name' => 'Line of Credit', 'status' => CapitalAccess::STATUS_ACTIVE]);

        $response = $this->service->show($strategy->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($strategy->id, $response->getData(true)['data']['id']);
    }

    public function test_show_throws_model_not_found_for_a_nonexistent_strategy(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->show(999999);
    }

    public function test_show_throws_model_not_found_for_an_inactive_strategy(): void
    {
        $strategy = CapitalAccess::create(['name' => 'Retired Strategy', 'status' => CapitalAccess::STATUS_INACTIVE]);

        $this->expectException(ModelNotFoundException::class);

        $this->service->show($strategy->id);
    }

    public function test_start_play_returns_unauthorized_when_no_borrower_is_authenticated(): void
    {
        $strategy = CapitalAccess::create(['name' => 'Line of Credit', 'status' => CapitalAccess::STATUS_ACTIVE]);

        $response = $this->service->startPlay($strategy->id);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_start_play_creates_a_running_play_status_for_the_authenticated_borrower(): void
    {
        $strategy = CapitalAccess::create(['name' => 'Line of Credit', 'status' => CapitalAccess::STATUS_ACTIVE]);
        $borrower = Borrower::factory()->create();
        $user = User::factory()->borrower($borrower)->create();
        $this->actingAs($user);

        $response = $this->service->startPlay($strategy->id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('borrower_capital_access_plays', [
            'borrower_id' => $borrower->id,
            'capital_access_id' => $strategy->id,
            'status' => BorrowerCapitalAccessPlay::STATUS_RUNNING,
        ]);
    }

    public function test_start_play_does_not_downgrade_an_already_completed_play_back_to_running(): void
    {
        $strategy = CapitalAccess::create(['name' => 'Line of Credit', 'status' => CapitalAccess::STATUS_ACTIVE]);
        $borrower = Borrower::factory()->create();
        $user = User::factory()->borrower($borrower)->create();
        $this->actingAs($user);

        BorrowerCapitalAccessPlay::create([
            'borrower_id' => $borrower->id,
            'capital_access_id' => $strategy->id,
            'status' => BorrowerCapitalAccessPlay::STATUS_COMPLETED,
        ]);

        $this->service->startPlay($strategy->id);

        $this->assertDatabaseHas('borrower_capital_access_plays', [
            'borrower_id' => $borrower->id,
            'capital_access_id' => $strategy->id,
            'status' => BorrowerCapitalAccessPlay::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseCount('borrower_capital_access_plays', 1);
    }
}
