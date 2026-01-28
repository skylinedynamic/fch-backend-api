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
        Schema::table('partner_subscriptions', function (Blueprint $table) {
            $table->foreignId('partner_plan_id')->nullable()->after('partner_id')->constrained('partner_plans');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partner_subscriptions', function (Blueprint $table) {
            $table->dropForeign(['partner_plan_id']);
            $table->dropColumn(['partner_plan_id']);
        });
    }
};
