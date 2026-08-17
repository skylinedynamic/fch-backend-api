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
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->boolean('is_partner_prepared')->default(false)->after('partner_id')->comment('1 for partner create, 0 for borrower create');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->dropColumn(['is_partner_prepared']);
        });
    }
};
