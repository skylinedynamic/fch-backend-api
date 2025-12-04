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
            $table->string('partner_type')->nullable()->after('partner_id')->comment('Partner type: Referral, Founding Partner, Executive Partner');
            $table->string('payment_type')->nullable()->after('partner_type')->comment('Payment type: One-Time Payment, Payment Plan (10-month plan)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partner_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['partner_type', 'payment_type']);
        });
    }
};
