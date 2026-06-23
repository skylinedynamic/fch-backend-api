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
        Schema::table('capital_accesses', function (Blueprint $table) {
            $table->boolean('allow_recent_late_payment')->default(true)->after('difficulty_modifier');
            $table->integer('late_payment_lookback_months')->nullable()->after('allow_recent_late_payment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('capital_accesses', function (Blueprint $table) {
            $table->dropColumn(['allow_recent_late_payment', 'late_payment_lookback_months']);
        });
    }
};
