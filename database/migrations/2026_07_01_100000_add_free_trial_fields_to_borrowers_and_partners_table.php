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
        // Add free trial fields to borrowers table after credit_profile_updated
        Schema::table('borrowers', function (Blueprint $table) {
            $table->boolean('is_free_trail')->default(false)->nullable()->after('credit_profile_updated');
            $table->date('free_trail_end_date')->nullable()->after('is_free_trail');
        });

        // Add free trial fields to partners table after total_paid_users
        Schema::table('partners', function (Blueprint $table) {
            $table->boolean('is_free_trail')->default(false)->nullable()->after('total_paid_users');
            $table->date('free_trail_end_date')->nullable()->after('is_free_trail');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('borrowers', function (Blueprint $table) {
            $table->dropColumn(['is_free_trail', 'free_trail_end_date']);
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['is_free_trail', 'free_trail_end_date']);
        });
    }
};
