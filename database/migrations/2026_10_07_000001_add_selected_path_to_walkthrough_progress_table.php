<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What the borrower chose to look at once their results were ready (see
     * OnboardingStepService::PATHS), and when. The borrower can change it, so
     * the time is of the latest choice.
     */
    public function up(): void
    {
        Schema::table('walkthrough_progress', function (Blueprint $table) {
            $table->string('selected_path', 32)->nullable()->after('skipped_steps');
            $table->timestamp('path_selected_at')->nullable()->after('selected_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('walkthrough_progress', function (Blueprint $table) {
            $table->dropColumn(['selected_path', 'path_selected_at']);
        });
    }
};
