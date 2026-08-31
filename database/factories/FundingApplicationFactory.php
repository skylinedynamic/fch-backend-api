<?php

namespace Database\Factories;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FundingApplication>
 *
 * Note: `admin_commission`, `admin_commission_amount`, `partner_commission` and
 * `partner_commission_amount` are listed in the model's $fillable but are NOT
 * real columns on the `funding_applications` table (they belong to the separate
 * `commissions` table) — they are intentionally omitted here. See final report
 * for this flagged as a production bug (dead fillable entries).
 */
class FundingApplicationFactory extends Factory
{
    protected $model = FundingApplication::class;

    public function definition(): array
    {
        return [
            'borrower_id' => Borrower::factory(),
            'partner_id' => null,
            'is_partner_prepared' => false,
            'status' => FundingApplication::ON_HOLD,
            'status_note' => null,

            // Business Information
            'full_name' => fake()->name(),
            'business_name' => fake()->company(),
            'business_email' => fake()->unique()->safeEmail(),
            'business_phone_no' => fake()->numerify('##########'),
            'business_country' => 'US',
            'business_country_code' => 'US',
            'business_address' => fake()->streetAddress(),
            'business_city' => fake()->city(),
            'business_state' => fake()->stateAbbr(),
            'business_postal_code' => fake()->postcode(),
            'industry_type' => 'Retail',
            'entity_type' => 'LLC',
            'years_in_business' => '2-5 years',
            'number_of_employees' => fake()->numberBetween(1, 50),

            // Ownership Information
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->date('Y-m-d', '-25 years'),
            'ssn' => fake()->numerify('#########'),
            'phone_number' => fake()->numerify('##########'),
            'citizenship_status' => 'US Citizen',
            'street_address' => fake()->streetAddress(),
            'country' => 'US',
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'zip_code' => fake()->postcode(),
            'ownership' => 100.00,
            'has_co_borrower' => false,

            // Financial Information
            'monthly_revenue' => fake()->randomFloat(2, 5000, 50000),
            'annual_revenue' => fake()->randomFloat(2, 60000, 600000),
            'business_bank_name' => fake()->company().' Bank',
            'business_checking_start_date' => fake()->date('Y-m-d', '-1 year'),
            'self_reported_personal_credit_score' => '700-750',
            'self_reported_business_credit_score' => '70-79',
            'has_business_debt' => false,
            'debt_balance' => null,

            // Funding Request
            'requested_amount' => fake()->randomFloat(2, 5000, 100000),
            'purpose_of_funds' => 'Working Capital',
            'approved_funding' => null,
            'purpose_note' => null,
            'preferred_funding_type' => 'Term Loan',
            'timeframe_needed' => 'Within 30 days',
            'collateral_available' => 'None',

            // Disclosures and consent
            'credit_pull_authorization' => false,
            'terms_conditions_acceptance' => false,

            // Federal Compliance Disclosures
            'filed_for_bankruptcy' => false,
            'any_pending_lawsuits' => false,
            'has_federal_loan' => false,
            'has_federal_debt' => false,
            'has_criminal_offense' => false,
            'has_parole_or_probation' => false,
            'suspended_or_debarred' => false,
            'any_outstanding_judgments_business' => false,
            'any_outstanding_judgments_owner' => false,
            'federal_compliance_disclosures_explanation' => null,

            // Attestation and authorization
            'attestation_authorization' => false,
            'signature_data' => null,

            // Other
            'funded_note' => null,
            'total_funded_amount' => null,
            'currency' => 'USD',
            'currency_symbol' => '$',
            'conversion_rate' => 1.00,

            'funding_sequence_id' => null,
            'funding_request_id' => null,

            'is_business_info_saved' => false,
            'is_ownership_info_saved' => false,
            'is_financial_info_saved' => false,
            'is_funding_request_saved' => false,
            'is_disclosures_saved' => false,
            'is_federal_compliance_saved' => false,

            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FundingApplication::PENDING,
        ]);
    }

    public function withCoBorrower(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_co_borrower' => true,
        ]);
    }
}
