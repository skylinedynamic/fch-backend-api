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
        Schema::table('capital_access_sequences', function (Blueprint $table) {
            $table->decimal('minimum_fico_requirement', 15, 2)->nullable()->after('access_day_time');
            $table->decimal('maximum_utilization_threshold', 15, 2)->nullable()->after('minimum_fico_requirement');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('capital_access_sequences', function (Blueprint $table) {
            $table->dropColumn(['minimum_fico_requirement', 'maximum_utilization_threshold']);
        });
    }
};
