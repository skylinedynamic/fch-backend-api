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
            $table->decimal('avg_bank_balance', 23, 2)->nullable()->after('monthly_revenue');
            $table->decimal('profit_margin', 8, 2)->nullable()->after('annual_revenue');
            $table->decimal('current_business_debt', 23, 2)->nullable()->after('profit_margin');
            $table->integer('fico_score')->nullable()->after('current_business_debt');
            $table->integer('paydex_score')->nullable()->after('fico_score');
            $table->integer('number_of_employees')->nullable()->after('years_in_business');
            $table->integer('nsf_days')->nullable()->after('number_of_employees');
            $table->string('funding_urgency')->nullable()->after('nsf_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->dropColumn([
                'avg_bank_balance',
                'profit_margin',
                'current_business_debt',
                'fico_score',
                'paydex_score',
                'number_of_employees',
                'nsf_days',
                'funding_urgency'
            ]);
        });
    }
};
