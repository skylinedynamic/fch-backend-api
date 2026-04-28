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
        Schema::table('credit_accounts', function (Blueprint $table) {
            $table->string('personal_guarantee')->nullable()->after('time_in_business_min_months');
            $table->string('business_credit_profile')->nullable()->after('personal_guarantee');
            $table->string('final_approval')->nullable()->after('business_credit_profile');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_accounts', function (Blueprint $table) {
            $table->dropColumn(['personal_guarantee', 'business_credit_profile', 'final_approval']);
        });
    }
};
