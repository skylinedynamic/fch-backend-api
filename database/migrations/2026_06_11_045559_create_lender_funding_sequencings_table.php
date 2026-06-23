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
        Schema::create('lender_funding_sequencings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('funding_sequencing_id')->constrained('funding_sequencings')->cascadeOnDelete();
            
            // Visibility logic
            $table->integer('min_fico')->nullable();
            $table->integer('min_tib_months')->nullable();
            $table->decimal('min_monthly_revenue', 15, 2)->nullable();
            $table->decimal('min_monthly_deposits', 15, 2)->nullable();
            $table->decimal('min_average_daily_balance', 15, 2)->nullable();
            $table->integer('max_nsf_count')->nullable();
            $table->integer('max_negative_days')->nullable();
            $table->decimal('min_dscr', 8, 2)->nullable();
            
            $table->json('allowed_states')->nullable();
            $table->json('restricted_states')->nullable();
            $table->json('allowed_industries')->nullable();
            $table->json('restricted_industries')->nullable();
            
            $table->boolean('require_completed_application')->default(false);
            $table->boolean('require_documents_uploaded')->default(false);
            $table->json('allowed_documents')->nullable();
            
            $table->boolean('manual_review_allowed')->default(false);
            $table->boolean('exception_allowed')->default(false);
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lender_funding_sequencings');
    }
};
