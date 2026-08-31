<?php

namespace Tests\Feature\FundingApplication;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FundingApplicationWizardTest extends TestCase
{
    protected Borrower $borrower;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        Storage::fake('public');

        $this->borrower = Borrower::factory()->create();
        $this->user = User::factory()->borrower($this->borrower)->create();
    }

    private function validBusinessInfoPayload(): array
    {
        return [
            'full_name' => 'John Doe',
            'business_name' => 'Acme Inc',
            'business_email' => 'business@example.com',
            'business_phone_no' => '5551234567',
            'business_country' => 'US',
            'business_country_code' => 'US',
            'business_address' => '123 Main St',
            'business_city' => 'Springfield',
            'business_state' => 'IL',
            'business_postal_code' => '62701',
            'industry_type' => 'Retail',
            'entity_type' => 'LLC',
            'years_in_business' => '2-5 years',
        ];
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/borrower/funding-application/business-info', $this->validBusinessInfoPayload());

        $response->assertStatus(401);
    }

    public function test_business_info_step_persists_data_and_flips_its_flag(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/funding-application/business-info', $this->validBusinessInfoPayload());

        $response->assertStatus(200)
            ->assertJsonPath('status', true);

        $this->assertDatabaseHas('funding_applications', [
            'borrower_id' => $this->borrower->id,
            'business_name' => 'Acme Inc',
            'is_business_info_saved' => 1,
        ]);
    }

    public function test_business_info_step_returns_a_flattened_422_on_missing_required_fields(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/funding-application/business-info', []);

        $response->assertStatus(422)
            ->assertJsonPath('status', false);

        $errors = $response->json('errors');
        $this->assertIsString($errors['business_name']);
        $this->assertIsString($errors['full_name']);
    }

    /**
     * getFundingInfo() falls back to the plain Borrower record when no
     * FundingApplication exists yet, so this returns 200 (not 404) as long as
     * the authenticated user has a borrower profile at all.
     */
    public function test_get_business_info_falls_back_to_the_borrower_record_when_nothing_has_been_saved_yet(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/borrower/funding-application/business-info');

        $response->assertStatus(200)
            ->assertJsonPath('status', true);
    }

    public function test_ownership_info_step_persists_ssn_and_flips_its_flag(): void
    {
        $existing = FundingApplication::factory()->create([
            'borrower_id' => $this->borrower->id,
            'status' => FundingApplication::ON_HOLD,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/funding-application/ownership-info', [
                'id' => $existing->id,
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'date_of_birth' => '1990-01-01',
                'ssn' => '123456789',
                'phone_number' => '5551234567',
                'citizenship_status' => 'US Citizen',
                'street_address' => '123 Main St',
                'city' => 'Springfield',
                'state' => 'IL',
                'zip_code' => '62701',
                'ownership' => 100,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('funding_applications', [
            'id' => $existing->id,
            'ssn' => '123456789',
            'is_ownership_info_saved' => 1,
        ]);
    }

    public function test_ownership_info_step_rejects_a_non_nine_digit_ssn(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/funding-application/ownership-info', [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'date_of_birth' => '1990-01-01',
                'ssn' => '123',
                'phone_number' => '5551234567',
                'citizenship_status' => 'US Citizen',
                'street_address' => '123 Main St',
                'city' => 'Springfield',
                'state' => 'IL',
                'zip_code' => '62701',
                'ownership' => 100,
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('ssn', $response->json('errors'));
    }

    public function test_financial_info_step_persists_data_and_flips_its_flag(): void
    {
        $existing = FundingApplication::factory()->create([
            'borrower_id' => $this->borrower->id,
            'status' => FundingApplication::ON_HOLD,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/funding-application/financial-info', [
                'id' => $existing->id,
                'monthly_revenue' => 10000,
                'business_checking_start_date' => '2023-01-01',
                'self_reported_personal_credit_score' => '700-750',
                'has_business_debt' => false,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('funding_applications', [
            'id' => $existing->id,
            'is_financial_info_saved' => 1,
        ]);
    }

    public function test_financial_info_step_requires_debt_balance_when_has_business_debt_is_true(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/funding-application/financial-info', [
                'monthly_revenue' => 10000,
                'business_checking_start_date' => '2023-01-01',
                'self_reported_personal_credit_score' => '700-750',
                'has_business_debt' => true,
                // debt_balance intentionally omitted
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('debt_balance', $response->json('errors'));
    }

    public function test_funding_request_step_persists_data_and_flips_its_flag(): void
    {
        $existing = FundingApplication::factory()->create([
            'borrower_id' => $this->borrower->id,
            'status' => FundingApplication::ON_HOLD,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/funding-application/funding-request', [
                'id' => $existing->id,
                'requested_amount' => 50000,
                'purpose_of_funds' => 'Working Capital',
                'approved_funding' => 'Term Loan',
                'preferred_funding_type' => 'Term Loan',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('funding_applications', [
            'id' => $existing->id,
            'is_funding_request_saved' => 1,
        ]);
    }

    public function test_disclosures_and_consent_step_requires_both_boxes_to_be_accepted(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/funding-application/disclosures-and-consent', [
                'credit_pull_authorization' => false,
                'terms_conditions_acceptance' => true,
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('credit_pull_authorization', $response->json('errors'));
    }

    public function test_disclosures_and_consent_step_succeeds_when_both_boxes_are_accepted(): void
    {
        $existing = FundingApplication::factory()->create([
            'borrower_id' => $this->borrower->id,
            'status' => FundingApplication::ON_HOLD,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/funding-application/disclosures-and-consent', [
                'id' => $existing->id,
                'credit_pull_authorization' => true,
                'terms_conditions_acceptance' => true,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('funding_applications', [
            'id' => $existing->id,
            'credit_pull_authorization' => 1,
            'terms_conditions_acceptance' => 1,
            'is_disclosures_saved' => 1,
        ]);
    }

    public function test_federal_compliance_step_requires_an_explanation_when_any_disclosure_flag_is_true(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/funding-application/federal-compliance-disclosures', [
                'filed_for_bankruptcy' => true,
                'any_pending_lawsuits' => false,
                'has_federal_loan' => false,
                'has_federal_debt' => false,
                'has_criminal_offense' => false,
                'has_parole_or_probation' => false,
                'suspended_or_debarred' => false,
                'any_outstanding_judgments_business' => false,
                'any_outstanding_judgments_owner' => false,
                // federal_compliance_disclosures_explanation intentionally omitted
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('federal_compliance_disclosures_explanation', $response->json('errors'));
    }

    public function test_federal_compliance_step_succeeds_when_all_flags_are_false(): void
    {
        $existing = FundingApplication::factory()->create([
            'borrower_id' => $this->borrower->id,
            'status' => FundingApplication::ON_HOLD,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/funding-application/federal-compliance-disclosures', [
                'id' => $existing->id,
                'filed_for_bankruptcy' => false,
                'any_pending_lawsuits' => false,
                'has_federal_loan' => false,
                'has_federal_debt' => false,
                'has_criminal_offense' => false,
                'has_parole_or_probation' => false,
                'suspended_or_debarred' => false,
                'any_outstanding_judgments_business' => false,
                'any_outstanding_judgments_owner' => false,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('funding_applications', [
            'id' => $existing->id,
            'is_federal_compliance_saved' => 1,
        ]);
    }

    public function test_attestation_step_returns_422_when_consents_are_not_accepted(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/borrower/funding-application/attestation-authorization', [
                'signature_name' => 'John Doe',
                'esign_consent' => false,
                'terms_consent' => true,
                'credit_consent' => true,
                'legal_binding_consent' => true,
                'timezone' => 'America/Chicago',
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('esign_consent', $response->json('errors'));
    }

    /**
     * Confirmed gap in this repo's CLAUDE.md: any authenticated Admin currently
     * has blanket access to every admin-side Funding/CreditBuilder endpoint —
     * there is no Spatie permission: middleware or ->can() gate on the admin
     * route group. This test documents the CURRENT behavior (access granted)
     * as a regression marker, it does not fix the gap.
     */
    public function test_authorization_gap_any_authenticated_admin_can_hit_admin_credit_builder_endpoints_with_no_specific_permission(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/credit-builder/tiers/fetch-tiers', []);

        // No Spatie permission is assigned to $admin at all, yet access is granted.
        $response->assertStatus(200);
    }

    public function test_authorization_gap_borrower_user_is_correctly_denied_the_same_admin_endpoint(): void
    {
        // Sanity check contrasting the gap above: role gating IS enforced,
        // only fine-grained permission gating is missing.
        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/admin/credit-builder/tiers/fetch-tiers', []);

        $response->assertStatus(403);
    }
}
