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
            $table->integer('subscription_term_months')->nullable()->after('billing_term')->comment('Subscription duration in months (e.g., 10 months)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partner_subscriptions', function (Blueprint $table) {
            $table->dropColumn('subscription_term_months');
        });
    }
};
