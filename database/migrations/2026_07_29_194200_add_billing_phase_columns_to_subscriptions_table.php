<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds all subscription billing phase tracking columns to the subscriptions table.
 *
 * In the Application-Led Billing Model, the subscriptions table is the
 * single source of truth for phase state. The subscription_phases table
 * is kept for historical log / backward compatibility but this table
 * mirrors its key fields for simpler queries and cron logic.
 *
 * Columns added:
 *  - platform_fee_start_date   : Date on which the platform fee phase begins
 *  - current_phase             : 'enrollment' or 'platform_fee'
 *  - installments_paid         : Number of enrollment installments paid so far
 *  - next_installment_date     : Next enrollment installment due date
 *  - next_platform_fee_date    : Next platform fee due date
 *  - enrollment_completed_at   : Timestamp when enrollment phase completed
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'platform_fee_start_date')) {
                $table->date('platform_fee_start_date')->nullable()->after('first_payment_date');
            }
            if (!Schema::hasColumn('subscriptions', 'current_phase')) {
                $table->string('current_phase')->default('enrollment')->after('status');
            }
            if (!Schema::hasColumn('subscriptions', 'installments_paid')) {
                $table->integer('installments_paid')->default(0)->after('current_phase');
            }
            if (!Schema::hasColumn('subscriptions', 'next_installment_date')) {
                $table->date('next_installment_date')->nullable()->after('installments_paid');
            }
            if (!Schema::hasColumn('subscriptions', 'next_platform_fee_date')) {
                $table->date('next_platform_fee_date')->nullable()->after('next_installment_date');
            }
            if (!Schema::hasColumn('subscriptions', 'enrollment_completed_at')) {
                $table->timestamp('enrollment_completed_at')->nullable()->after('next_platform_fee_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $columns = [
                'platform_fee_start_date',
                'current_phase',
                'installments_paid',
                'next_installment_date',
                'next_platform_fee_date',
                'enrollment_completed_at',
            ];

            $existing = array_filter($columns, fn($col) => Schema::hasColumn('subscriptions', $col));

            if (!empty($existing)) {
                $table->dropColumn(array_values($existing));
            }
        });
    }
};
