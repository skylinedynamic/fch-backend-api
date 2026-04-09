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
        Schema::create('credit_accounts', function (Blueprint $table) {
            $table->id();

            // Image 1 columns
            $table->string('card_name')->nullable();
            $table->string('issuer')->nullable();
            $table->string('credit_category')->nullable();
            $table->unsignedSmallInteger('difficulty_rank')->nullable();
            $table->string('difficulty_tier', 10)->nullable();
            $table->string('engine_eligibility_band')->nullable();
            $table->string('approval_level')->nullable();
            $table->unsignedSmallInteger('typical_personal_fico_min')->nullable();
            $table->unsignedSmallInteger('typical_personal_fico_ideal')->nullable();
            $table->unsignedSmallInteger('time_in_business_min_months')->nullable();

            // Image 2 columns
            $table->string('revenue_expectation')->nullable();
            $table->string('business_credit_required')->nullable();
            $table->string('pg_required')->nullable();
            $table->string('cash_based')->nullable();
            $table->string('velocity_sensitive')->nullable();
            $table->string('relationship_required')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('portal_tier_unlock')->nullable();
            $table->string('borrower_exposure')->nullable();
            $table->string('sequencing_priority')->nullable();
            $table->string('image_url')->nullable();
            $table->string('reference_url', 500)->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_accounts');
    }
};
