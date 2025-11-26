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
            $table->date('date_of_birth')->nullable()->after('country');
            $table->string('country_code')->nullable()->after('date_of_birth');
            $table->string('last_4_ssn')->nullable()->after('country_code');
            $table->string('home_phone')->nullable()->after('last_4_ssn');

            $table->string('business_city')->nullable()->after('business_website');
            $table->string('business_state')->nullable()->after('business_city');
            $table->string('business_zip_code')->nullable()->after('business_state');
            $table->string('business_country')->nullable()->after('business_zip_code');
            $table->string('business_address')->nullable()->after('business_country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->dropColumn('home_phone');
            $table->dropColumn('last_4_ssn');
            $table->dropColumn('country_code');
            $table->dropColumn('date_of_birth');
            $table->dropColumn('business_city');
            $table->dropColumn('business_state');
            $table->dropColumn('business_zip_code');
            $table->dropColumn('business_country');
            $table->dropColumn('business_address');
        });
    }
};
