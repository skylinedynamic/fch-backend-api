<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Borrower\PreQualificationRequest;


return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pre_qualification_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('borrower_id')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('business_website')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('state_of_incorporation')->nullable();
            $table->string('business_name')->nullable();
            $table->string('industry_type')->nullable();
            $table->string('business_structure')->nullable();
            $table->decimal('monthly_revenue', 15, 2)->nullable();
            $table->decimal('annual_revenue', 15, 2)->nullable();
            $table->string('time_in_business')->nullable();
            $table->decimal('funding_requested_amount', 15, 2)->nullable();
            $table->string('funding_urgency')->nullable();
            $table->string('purpose_of_funding')->nullable();
            $table->string('personal_credit_score')->nullable();
            $table->string('estimated_business_credit_score')->nullable();
            $table->boolean('existing_business_debt')->default(false);
            $table->decimal('debt_balance', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default(PreQualificationRequest::PENDING)->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Indexes for common queries
            $table->index('borrower_id');
            $table->index('full_name');
            $table->index('email');
            $table->index('phone');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pre_qualification_requests');
    }
};
