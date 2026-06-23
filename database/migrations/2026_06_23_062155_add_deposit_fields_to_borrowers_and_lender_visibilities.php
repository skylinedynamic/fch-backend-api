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
        Schema::table('borrowers', function (Blueprint $table) {
            $table->decimal('average_monthly_deposits', 15, 2)->nullable()->after('avg_bank_balance');
            $table->integer('monthly_deposit_count')->nullable()->after('average_monthly_deposits');
        });

        Schema::table('lender_visibilities', function (Blueprint $table) {
            $table->decimal('min_avg_deposits', 15, 2)->nullable()->after('min_monthly_deposits');
            $table->integer('min_deposit_count')->nullable()->after('min_avg_deposits');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->dropColumn(['average_monthly_deposits', 'monthly_deposit_count']);
        });

        Schema::table('lender_visibilities', function (Blueprint $table) {
            $table->dropColumn(['min_avg_deposits', 'min_deposit_count']);
        });
    }
};
