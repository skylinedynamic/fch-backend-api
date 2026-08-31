<?php

namespace Tests\Feature\FundingApplication;

use App\Models\Borrower\Borrower;
use App\Models\Funding\FundingSequencing;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\TestCase;

/**
 * This repo's CLAUDE.md flags Api/V1/Borrower/Funding/FundingRequestController
 * as one of the 13+ controllers that still call response()->json() directly
 * instead of the App\Helpers\ApiResponse helper. These tests assert its
 * ACTUAL current response shape ({success, message[, data]}) rather than
 * rewriting the controller to the ApiResponse ({status, message, data}) shape.
 */
class FundingRequestControllerTest extends TestCase
{
    protected Borrower $borrower;

    protected User $user;

    protected FundingSequencing $sequence;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->borrower = Borrower::factory()->create();
        $this->user = User::factory()->borrower($this->borrower)->create();
        $this->sequence = FundingSequencing::create([
            'name' => 'Term Loan',
            'require_documents_uploaded' => false,
        ]);
    }

    public function test_store_uses_the_raw_json_response_shape_not_the_api_response_helper(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/funding/requests', [
                'funding_sequence_id' => $this->sequence->id,
                'requested_amount' => 25000,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['success', 'message', 'data'])
            ->assertJsonPath('success', true);

        // Confirming the gap: this is NOT the {status, message, data} shape
        // that ApiResponse::success() would produce.
        $this->assertArrayNotHasKey('status', $response->json());

        $this->assertDatabaseHas('funding_requests', [
            'borrower_id' => $this->borrower->id,
            'funding_sequence_id' => $this->sequence->id,
        ]);
    }

    public function test_store_returns_laravels_default_422_shape_on_validation_failure_not_api_response(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/funding/requests', []);

        // StoreFundingRequestRequest extends BaseApiRequest but validation
        // failure is handled by Laravel's exception pipeline before the
        // controller's own try/catch ever runs, so the 422 body here is
        // ApiResponse::validationError()'s flattened {field: message} shape
        // via failedValidation() — assert that flattened shape explicitly.
        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertIsString($errors['funding_sequence_id']);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/borrower/funding/requests', [
            'funding_sequence_id' => $this->sequence->id,
            'requested_amount' => 25000,
        ]);

        $response->assertStatus(401);
    }
}
