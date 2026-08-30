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
            $table->dropColumn('nsf_days');
            $table->integer('nsf_count')->nullable()->after('number_of_employees');
            $table->integer('negative_days')->nullable()->after('nsf_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->dropColumn(['nsf_count', 'negative_days']);
            $table->integer('nsf_days')->nullable();
        });
    }
};
