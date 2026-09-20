<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bring the embedded form up to the borrower funding application.
 *
 * The embed form started life as a consumer loan lead capture (living and
 * employer information) while the portal's own application collects a BUSINESS
 * funding application. A partner therefore had to re-key everything by hand
 * after every conversion. These columns are named exactly as their
 * `funding_applications` counterparts so the conversion is a straight copy.
 *
 * The original consumer columns (home_address, postal_code, birthday,
 * credit_score, desired_funding_amount, monthly_housing_payment,
 * housing_status, employer_*, occupation, annual_income) are deliberately left
 * in place: submissions taken before this change hold their only copy of that
 * data, and dropping them would silently empty those records. They are marked
 * deprecated on the model and are no longer collected by the form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embedded_form_submissions', function (Blueprint $table) {
            // Business Information
            $table->string('full_name')->nullable()->after('form_config_id');
            $table->string('business_name')->nullable()->after('full_name');
            $table->string('business_email')->nullable()->after('business_name');
            $table->string('business_phone_no')->nullable()->after('business_email');
            $table->string('business_country')->nullable()->after('business_phone_no');
            $table->string('business_country_code', 10)->nullable()->after('business_country');
            $table->string('business_address')->nullable()->after('business_country_code');
            $table->string('business_city')->nullable()->after('business_address');
            $table->string('business_state')->nullable()->after('business_city');
            $table->string('business_postal_code', 20)->nullable()->after('business_state');
            $table->string('industry_type')->nullable()->after('business_postal_code');
            $table->string('entity_type')->nullable()->after('industry_type');
            $table->string('years_in_business')->nullable()->after('entity_type');
            $table->integer('number_of_employees')->nullable()->after('years_in_business');

            // Ownership Information.
            //
            // first_name / last_name / email / phone / city / state already exist
            // and carry the same meaning, so they are reused rather than doubled.
            $table->date('date_of_birth')->nullable()->after('phone');
            $table->string('citizenship_status')->nullable()->after('date_of_birth');
            $table->string('street_address')->nullable()->after('citizenship_status');
            $table->string('country')->nullable()->after('street_address');
            $table->string('zip_code', 20)->nullable()->after('country');
            $table->decimal('ownership', 8, 2)->nullable()->after('zip_code');
            $table->boolean('has_co_borrower')->default(false)->after('ownership');

            // Financial Information
            $table->decimal('monthly_revenue', 15, 2)->nullable()->after('has_co_borrower');
            $table->decimal('annual_revenue', 15, 2)->nullable()->after('monthly_revenue');
            $table->string('business_bank_name')->nullable()->after('annual_revenue');
            $table->date('business_checking_start_date')->nullable()->after('business_bank_name');
            $table->string('self_reported_personal_credit_score')->nullable()->after('business_checking_start_date');
            $table->string('self_reported_business_credit_score')->nullable()->after('self_reported_personal_credit_score');
            $table->boolean('has_business_debt')->default(false)->after('self_reported_business_credit_score');
            $table->decimal('debt_balance', 15, 2)->nullable()->after('has_business_debt');

            // Funding Request
            $table->decimal('requested_amount', 15, 2)->nullable()->after('debt_balance');
            $table->string('purpose_of_funds')->nullable()->after('requested_amount');
            $table->string('purpose_note', 500)->nullable()->after('purpose_of_funds');
            $table->string('approved_funding')->nullable()->after('purpose_note');
            $table->string('preferred_funding_type')->nullable()->after('approved_funding');
            $table->string('timeframe_needed')->nullable()->after('preferred_funding_type');
            $table->string('collateral_available', 500)->nullable()->after('timeframe_needed');

            // Disclosures and consent
            $table->boolean('credit_pull_authorization')->default(false)->after('collateral_available');
            $table->boolean('terms_conditions_acceptance')->default(false)->after('credit_pull_authorization');

            // Federal Compliance Disclosures
            $table->boolean('filed_for_bankruptcy')->default(false)->after('terms_conditions_acceptance');
            $table->boolean('any_pending_lawsuits')->default(false)->after('filed_for_bankruptcy');
            $table->boolean('has_federal_loan')->default(false)->after('any_pending_lawsuits');
            $table->boolean('has_federal_debt')->default(false)->after('has_federal_loan');
            $table->boolean('has_criminal_offense')->default(false)->after('has_federal_debt');
            $table->boolean('has_parole_or_probation')->default(false)->after('has_criminal_offense');
            $table->boolean('suspended_or_debarred')->default(false)->after('has_parole_or_probation');
            $table->boolean('any_outstanding_judgments_business')->default(false)->after('suspended_or_debarred');
            $table->boolean('any_outstanding_judgments_owner')->default(false)->after('any_outstanding_judgments_business');
            $table->longText('federal_compliance_disclosures_explanation')->nullable()->after('any_outstanding_judgments_owner');

            // Attestation and authorization
            $table->boolean('attestation_authorization')->default(false)->after('federal_compliance_disclosures_explanation');
            $table->string('signature_name')->nullable()->after('attestation_authorization');
            $table->boolean('esign_consent')->default(false)->after('signature_name');
            $table->boolean('terms_consent')->default(false)->after('esign_consent');
            $table->boolean('credit_consent')->default(false)->after('terms_consent');
            $table->boolean('legal_binding_consent')->default(false)->after('credit_consent');
            // Per-consent timestamps plus the applicant's timezone, kept as one
            // JSON blob the way funding_applications.signature_data is.
            $table->json('signature_data')->nullable()->after('legal_binding_consent');
            $table->string('application_version')->nullable()->after('signature_data');
            $table->string('submission_timezone')->nullable()->after('application_version');

            // Conversion
            $table->unsignedBigInteger('converted_funding_application_id')
                ->nullable()
                ->after('converted_borrower_id')
                ->comment('Funding application created when this submission was converted');

            // Named explicitly: the convention-generated name would be
            // `embedded_form_submissions_converted_funding_application_id_foreign`,
            // which is 66 characters and over MySQL's 64 character limit.
            $table->foreign('converted_funding_application_id', 'efs_converted_funding_application_foreign')
                ->references('id')
                ->on('funding_applications')
                ->nullOnDelete();

            $table->index('business_name');
            $table->index('industry_type');
        });
    }

    public function down(): void
    {
        Schema::table('embedded_form_submissions', function (Blueprint $table) {
            $table->dropForeign('efs_converted_funding_application_foreign');
            $table->dropIndex(['business_name']);
            $table->dropIndex(['industry_type']);

            $table->dropColumn([
                'full_name', 'business_name', 'business_email', 'business_phone_no',
                'business_country', 'business_country_code', 'business_address',
                'business_city', 'business_state', 'business_postal_code',
                'industry_type', 'entity_type', 'years_in_business', 'number_of_employees',

                'date_of_birth', 'citizenship_status', 'street_address', 'country',
                'zip_code', 'ownership', 'has_co_borrower',

                'monthly_revenue', 'annual_revenue', 'business_bank_name',
                'business_checking_start_date', 'self_reported_personal_credit_score',
                'self_reported_business_credit_score', 'has_business_debt', 'debt_balance',

                'requested_amount', 'purpose_of_funds', 'purpose_note', 'approved_funding',
                'preferred_funding_type', 'timeframe_needed', 'collateral_available',

                'credit_pull_authorization', 'terms_conditions_acceptance',

                'filed_for_bankruptcy', 'any_pending_lawsuits', 'has_federal_loan',
                'has_federal_debt', 'has_criminal_offense', 'has_parole_or_probation',
                'suspended_or_debarred', 'any_outstanding_judgments_business',
                'any_outstanding_judgments_owner', 'federal_compliance_disclosures_explanation',

                'attestation_authorization', 'signature_name', 'esign_consent',
                'terms_consent', 'credit_consent', 'legal_binding_consent',
                'signature_data', 'application_version', 'submission_timezone',

                'converted_funding_application_id',
            ]);
        });
    }
};
