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
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'current_cycle_invoice_id')) {
                $table->unsignedBigInteger('current_cycle_invoice_id')->nullable()->after('subscription_end_date');
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $columnsToDrop = [];
            foreach (['platform_fee_start_date', 'stripe_subscription_id', 'next_installment_date', 'next_platform_fee_date'] as $column) {
                if (Schema::hasColumn('subscriptions', $column)) {
                    $columnsToDrop[] = $column;
                }
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'current_cycle_invoice_id')) {
                $table->dropColumn('current_cycle_invoice_id');
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'platform_fee_start_date')) {
                $table->date('platform_fee_start_date')->nullable();
            }
            if (!Schema::hasColumn('subscriptions', 'stripe_subscription_id')) {
                $table->string('stripe_subscription_id', 255)->nullable();
            }
            if (!Schema::hasColumn('subscriptions', 'next_installment_date')) {
                $table->date('next_installment_date')->nullable();
            }
            if (!Schema::hasColumn('subscriptions', 'next_platform_fee_date')) {
                $table->date('next_platform_fee_date')->nullable();
            }
        });
    }
};
