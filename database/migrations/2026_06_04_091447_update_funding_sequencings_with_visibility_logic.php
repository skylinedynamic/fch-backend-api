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
        Schema::table('funding_sequencings', function (Blueprint $table) {
            // Drop old unused columns
            $table->dropColumn([
                'speed_to_funds',
                'uses',
                'qualify_reasons',
                'missing_documents',
                'readiness_factors',
                'eligibility_status',
                'eligibility_label'
            ]);

            // Add new Visibility Logic Fields
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
            $table->boolean('manual_review_allowed')->default(false);
            $table->boolean('exception_allowed')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('funding_sequencings', function (Blueprint $table) {
            // Re-add old columns
            $table->string('speed_to_funds')->nullable();
            $table->string('uses')->nullable();
            $table->json('qualify_reasons')->nullable();
            $table->json('missing_documents')->nullable();
            $table->json('readiness_factors')->nullable();
            $table->string('eligibility_status')->nullable();
            $table->string('eligibility_label')->nullable();

            // Drop new columns
            $table->dropColumn([
                'min_fico',
                'min_tib_months',
                'min_monthly_revenue',
                'min_monthly_deposits',
                'min_average_daily_balance',
                'max_nsf_count',
                'max_negative_days',
                'min_dscr',
                'allowed_states',
                'restricted_states',
                'allowed_industries',
                'restricted_industries',
                'require_completed_application',
                'require_documents_uploaded',
                'manual_review_allowed',
                'exception_allowed'
            ]);
        });
    }
};
