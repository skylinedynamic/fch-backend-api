<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('funding_applications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('borrower_id')->constrained()->onDelete('cascade');
            
            // Business Information
            $table->string('full_name')->nullable()->index();
            $table->string('business_name')->nullable()->index();
            $table->string('business_email')->nullable()->index();
            $table->string('business_phone_no')->nullable();
            $table->string('business_country')->nullable();
            $table->string('business_country_code')->nullable();
            $table->string('business_address')->nullable();
            $table->string('business_city')->nullable();
            $table->string('business_state')->nullable();
            $table->string('business_postal_code')->nullable();
            $table->string('industry_type')->nullable()->index();
            $table->string('entity_type')->nullable()->index();
            $table->string('years_in_business')->nullable();
            $table->integer('number_of_employees')->nullable();
            
            // Ownership Information
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('ssn')->nullable();
            $table->string('citizenship_status')->nullable();
            $table->string('street_address')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip_code')->nullable();
            $table->decimal('ownership', 8, 2)->nullable();
            $table->boolean('has_co_borrower')->default(false);

            // Financial Information
            $table->decimal('monthly_revenue', 15, 2)->nullable();
            $table->decimal('annual_revenue', 15, 2)->nullable();
            $table->string('business_bank_name')->nullable();
            $table->date('business_checking_start_date')->nullable();
            $table->string('self_reported_personal_credit_score')->nullable();
            $table->string('self_reported_business_credit_score')->nullable();
            $table->boolean('has_business_debt')->default(false);
            $table->decimal('debt_balance', 15, 2)->nullable();

            // Funding Request
            $table->decimal('requested_amount', 15, 2)->nullable();
            $table->string('purpose_of_funds')->nullable();
            $table->string('preferred_funding_type')->nullable();
            $table->string('timeframe_needed')->nullable();
            $table->string('collateral_available')->nullable();

            // Disclosures and consent
            $table->boolean('credit_pull_authorization')->default(false);
            $table->boolean('terms_conditions_acceptance')->default(false);

            // Federal Compliance Disclosures
            $table->boolean('filed_for_bankruptcy')->default(false);
            $table->boolean('any_pending_lawsuits')->default(false);
            $table->boolean('has_federal_loan')->default(false);
            $table->boolean('has_federal_debt')->default(false);
            $table->boolean('has_criminal_offense')->default(false);
            $table->boolean('has_parole_or_probation')->default(false);
            $table->boolean('suspended_or_debarred')->default(false);
            $table->boolean('any_outstanding_judgments_business')->default(false);
            $table->boolean('any_outstanding_judgments_owner')->default(false);
            $table->longText('federal_compliance_disclosures_explanation')->nullable();

            //others
            $table->string('status')->nullable();
            $table->string('status_note')->nullable();

            // Attestation and authorization
            $table->boolean('attestation_authorization')->default(false);
            $table->softDeletes();
            $table->timestamps();
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('funding_applications');
    }
};
