<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('group')->nullable()->after('id');
            $table->integer('total_seat_count')->nullable()->after('entity_type');
            $table->renameColumn('total_installments_count', 'total_installment_count_upto');
            $table->dropColumn(['subscription_period_month', 'payment_amount']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('group');
            $table->dropColumn('total_seat_count');
            $table->renameColumn('total_installment_count_upto', 'total_installments_count');
            $table->decimal('subscription_period_month', 10, 2)->nullable();
            $table->decimal('payment_amount', 10, 2)->nullable();
        });
    }
};
