<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing now runs on a single cycle date.
 *
 * subscriptions carried two: subscription_end_date ("paid through") and
 * expected_end_date ("when the cron next bills"). They existed as a pair because a
 * missed cycle used to be rolled forward without payment - the invoice was expired,
 * expected_end_date advanced, and the gap between the two recorded how far behind
 * the customer had fallen.
 *
 * That rollover is gone. Only one invoice may be open at a time now, and no further
 * invoice is raised until it is paid, so a cycle can never advance without a payment
 * and the two dates can never diverge. subscription_end_date carries both meanings:
 * what the customer is paid through, and when they are billed next.
 *
 * NOTE: down() restores the column but not the values - they cannot be
 * reconstructed, and nothing reads them any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('subscriptions', 'expected_end_date')) {
            return;
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('expected_end_date');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('subscriptions', 'expected_end_date')) {
            return;
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->date('expected_end_date')->nullable()->after('subscription_end_date');
        });
    }
};
