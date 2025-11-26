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
            $table->string('country_code')->nullable()->after('country');
            $table->string('last_4_ssn')->nullable()->after('country_code');
            $table->string('home_phone')->nullable()->after('last_4_ssn');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->dropColumn('last_4_ssn');
            $table->dropColumn('home_phone');
            $table->dropColumn('country_code');
        });
    }
};
