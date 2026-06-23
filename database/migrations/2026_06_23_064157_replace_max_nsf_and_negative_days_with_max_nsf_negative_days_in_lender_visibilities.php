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
        Schema::table('lender_visibilities', function (Blueprint $table) {
            $table->dropColumn(['max_nsf_count', 'max_negative_days']);
            $table->integer('max_nsf_negative_days')->nullable()->after('min_average_daily_balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lender_visibilities', function (Blueprint $table) {
            $table->dropColumn('max_nsf_negative_days');
            $table->integer('max_nsf_count')->nullable()->after('min_average_daily_balance');
            $table->integer('max_negative_days')->nullable()->after('max_nsf_count');
        });
    }
};
