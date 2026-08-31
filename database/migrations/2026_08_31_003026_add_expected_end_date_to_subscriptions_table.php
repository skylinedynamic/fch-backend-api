<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * subscription_end_date now means "paid through this date" — it only moves on
     * an actual successful payment. expected_end_date is the new scheduling field:
     * it's what generateDueInvoices()/createInvoiceIfDue() actually check against,
     * and it's the one that advances when a cycle is missed and rolled forward.
     * The gap between the two fields is, by construction, how far behind a
     * subscription actually is.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->date('expected_end_date')->nullable()->after('subscription_end_date');
        });

        // Backfill: for existing rows, scheduling was previously driven entirely by
        // subscription_end_date, so start expected_end_date in sync with it.
        DB::table('subscriptions')
            ->whereNotNull('subscription_end_date')
            ->update(['expected_end_date' => DB::raw('subscription_end_date')]);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('expected_end_date');
        });
    }
};
