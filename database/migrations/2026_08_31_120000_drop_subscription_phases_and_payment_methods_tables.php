<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop two tables that no longer back any code path.
 *
 * subscription_phases
 *   A side table that mirrored the billing phase. Everything it held now lives on
 *   subscriptions itself: phase -> current_phase, installments_paid,
 *   enrollment_completed_at, and next_installment_date / next_platform_fee_date ->
 *   expected_end_date (one field, since current_phase says which kind of cycle is
 *   next). Its relations, scopes and eager loads have been removed; the only real
 *   consumers were three sort branches in SubscriptionService, now pointed at the
 *   columns above.
 *
 * payment_methods
 *   A per-partner key/value store of gateway credentials, superseded by
 *   partner_payment_providers (which holds the same values in an encrypted
 *   configuration column, populated for every partner). Nothing read it: its only
 *   consumer was PaymentMethodService::getSettings(), which was itself never
 *   called, and the one-time PartnerPaymentProviderSeeder that copied the data
 *   across. Both have been removed.
 *
 * IMPORTANT: run the data repair BEFORE this migration if it has not run yet —
 * php artisan db:seed --class=RepairSubscriptionBillingDataSeeder
 * Nothing in the repair reads these tables, but restoring them afterwards is only
 * possible from a backup, so take one first: the down() below recreates the schema
 * but cannot bring the rows back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('subscription_phases');
        Schema::dropIfExists('payment_methods');
    }

    /**
     * Recreates the structures only. The rows are not recoverable here — restore
     * them from a database backup if they are ever needed again.
     */
    public function down(): void
    {
        Schema::create('subscription_phases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id');
            $table->string('phase');
            $table->integer('installments_paid')->default(0);
            $table->timestamp('enrollment_completed_at')->nullable();
            $table->date('next_installment_date')->nullable();
            $table->date('next_platform_fee_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Schema::create('payment_methods', function (Blueprint $table) {
        //     $table->id();
        //     $table->unsignedBigInteger('partner_id')->nullable();
        //     $table->string('key');
        //     $table->text('value')->nullable();
        //     $table->timestamps();
        // });
    }
};
