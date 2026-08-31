<?php

namespace Tests\Feature\PreQualification;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PreQualificationWizardTest extends TestCase
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
        $response = $this->postJson('/api/v1/borrower/pre-qualification/personal-info', []);

        $response->assertStatus(401);
    }

    public function test_personal_info_step_succeeds_and_creates_an_on_hold_request(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/pre-qualification/personal-info', [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
                'phone_number' => '5551234567',
                'state_of_incorporation' => 'CA',
            ]);

        $response->assertStatus(200)->assertJsonPath('status', true);

        $this->assertDatabaseHas('pre_qualification_requests', [
            'borrower_id' => $this->borrower->id,
            'first_name' => 'John',
            'status' => PreQualificationRequest::ON_HOLD,
        ]);
    }

    public function test_personal_info_step_rejects_an_invalid_email(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/pre-qualification/personal-info', [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'not-an-email',
                'phone_number' => '5551234567',
                'state_of_incorporation' => 'CA',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('email', $response->json('errors'));
    }

    public function test_business_info_step_succeeds(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/pre-qualification/business-info', [
                'business_name' => 'Acme Inc',
                'industry_type' => 'Retail',
                'business_structure' => 'LLC',
                'monthly_revenue' => 10000,
                'annual_revenue' => 120000,
                'years_in_business' => '2-5 years',
            ]);

        $response->assertStatus(200)->assertJsonPath('status', true);

        $this->assertDatabaseHas('pre_qualification_requests', [
            'borrower_id' => $this->borrower->id,
            'business_name' => 'Acme Inc',
        ]);
    }

    public function test_business_info_step_rejects_a_negative_monthly_revenue(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/pre-qualification/business-info', [
                'business_name' => 'Acme Inc',
                'industry_type' => 'Retail',
                'business_structure' => 'LLC',
                'monthly_revenue' => -100,
                'annual_revenue' => 120000,
                'years_in_business' => '2-5 years',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('monthly_revenue', $response->json('errors'));
    }

    public function test_funding_info_step_requires_a_purpose_note_when_purpose_is_other(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/pre-qualification/funding-info', [
                'funding_requested_amount' => 50000,
                'funding_urgency' => 'Within 30 days',
                'purpose_of_funding' => PreQualificationRequest::PURPOSE_OF_FUNDING_OPTIONS['Other'],
                'personal_credit_score' => '700-750',
                'estimated_business_credit_score' => '70-79',
                'existing_business_debt' => false,
                // purpose_note intentionally omitted
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('purpose_note', $response->json('errors'));
    }

    public function test_funding_info_step_succeeds_without_a_purpose_note_for_a_non_other_purpose(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/pre-qualification/funding-info', [
                'funding_requested_amount' => 50000,
                'funding_urgency' => 'Within 30 days',
                'purpose_of_funding' => PreQualificationRequest::PURPOSE_OF_FUNDING_OPTIONS['Expansion'],
                'personal_credit_score' => '700-750',
                'estimated_business_credit_score' => '70-79',
                'existing_business_debt' => false,
            ]);

        $response->assertStatus(200)->assertJsonPath('status', true);

        $this->assertDatabaseHas('pre_qualification_requests', [
            'borrower_id' => $this->borrower->id,
            'funding_requested_amount' => 50000,
        ]);
    }

    public function test_submit_moves_the_request_to_pending_and_returns_success(): void
    {
        Mail::fake();

        $record = PreQualificationRequest::create([
            'borrower_id' => $this->borrower->id,
            'partner_id' => $this->user->partner_id,
            'status' => PreQualificationRequest::ON_HOLD,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/pre-qualification/submit', ['id' => $record->id]);

        $response->assertStatus(200)->assertJsonPath('status', true);
        $this->assertSame(PreQualificationRequest::PENDING, $record->fresh()->status);
    }

    public function test_submit_returns_a_422_when_the_id_does_not_exist_at_all(): void
    {
        // SubmitPreQualificationRequest validates `exists:pre_qualification_requests,id`,
        // so a nonexistent id is rejected before the controller/service ever run.
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/pre-qualification/submit', ['id' => 999999]);

        $response->assertStatus(422)->assertJsonPath('status', false);
    }

    public function test_submit_returns_a_500_when_the_matching_request_belongs_to_a_different_borrower(): void
    {
        $otherBorrower = Borrower::factory()->create();
        $otherRecord = PreQualificationRequest::create([
            'borrower_id' => $otherBorrower->id,
            'status' => PreQualificationRequest::ON_HOLD,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/pre-qualification/submit', ['id' => $otherRecord->id]);

        $response->assertStatus(500)->assertJsonPath('status', false);
    }

    /**
     * CONFIRMED LIVE BUG (documented, not fixed here): when a borrower has no
     * existing ON_HOLD PreQualificationRequest yet, prequalificationRequest()
     * builds $mergedData WITHOUT an 'id' key (only the "existing record"
     * branch sets $mergedData['id']), then casts it to stdClass and passes it
     * to PreQualificationResource, which unconditionally reads ->id. That
     * throws "Undefined property: stdClass::$id" (surfaced here as a 500),
     * so a first-time borrower can never successfully load this endpoint.
     */
    public function test_index_currently_errors_for_a_borrower_with_no_existing_request_confirmed_bug(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/borrower/pre-qualification');

        $response->assertStatus(500)->assertJsonPath('status', false);
    }

    public function test_index_succeeds_when_an_on_hold_request_already_exists(): void
    {
        PreQualificationRequest::create([
            'borrower_id' => $this->borrower->id,
            'status' => PreQualificationRequest::ON_HOLD,
            'first_name' => 'John',
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/borrower/pre-qualification');

        $response->assertStatus(200)->assertJsonPath('status', true);
    }

    public function test_show_returns_404_for_an_on_hold_request(): void
    {
        $record = PreQualificationRequest::create([
            'borrower_id' => $this->borrower->id,
            'status' => PreQualificationRequest::ON_HOLD,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/borrower/pre-qualification/'.$record->id);

        $response->assertStatus(404)->assertJsonPath('status', false);
    }
}
