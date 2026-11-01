<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUG-005: when the scheduler stops auto-charging an invoice, and why -
 * already paid (a succeeded payment covers it) or too many failed attempts.
 * Null means it is still auto-charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('auto_charge_stopped_at')->nullable()->after('payment_date');
            $table->string('auto_charge_stop_reason', 40)->nullable()->after('auto_charge_stopped_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['auto_charge_stopped_at', 'auto_charge_stop_reason']);
        });
    }
};
