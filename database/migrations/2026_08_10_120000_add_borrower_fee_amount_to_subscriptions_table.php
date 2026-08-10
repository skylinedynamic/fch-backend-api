<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Adds borrower_fee_amount to subscriptions table.
     * This column records the fixed fee a WL/Certified partner pays per borrower
     * they create — stored on the borrower's subscription row so it can be
     * reported and audited alongside the borrower's own enrollment amount.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->decimal('borrower_fee_amount', 10, 2)
                ->nullable()
                ->after('subscription_amount_per_month');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('borrower_fee_amount');
        });
    }
};
