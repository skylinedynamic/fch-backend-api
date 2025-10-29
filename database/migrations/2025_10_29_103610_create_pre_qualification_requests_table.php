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
        Schema::create('pre_qualification_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();

            // Personal Info
            $table->string('full_name');
            $table->string('business_website')->nullable();
            $table->string('email');
            $table->string('phone');
            $table->string('state_of_incorporation');

            // Business Details
            $table->string('business_name');
            $table->string('industry');
            $table->string('business_structure');
            $table->decimal('monthly_revenue', 15, 2);
            $table->decimal('annual_revenue', 15, 2)->nullable();
            $table->string('time_in_business');

            // Funding Needs
            $table->decimal('funding_requested_amount', 15, 2);
            $table->string('funding_urgency');
            $table->string('purpose_of_funding');
            $table->string('personal_credit_score');
            $table->string('estimated_business_credit_score')->nullable();
            $table->boolean('existing_business_debt')->default(false);
            $table->text('notes')->nullable();

            $table->string('status')->default('pending')->nullable();
            $table->foreignId('assign_to')->nullable()->constrained('users')->nullOnDelete();

            // Indexes
            $table->index('user_id');
            $table->index('company_id');
            $table->index('assign_to');
            $table->index('status');
            $table->index(['user_id', 'status']);

            $table->softDeletes();
            $table->timestamps();
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
