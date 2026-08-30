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
        Schema::table('funding_reviews', function (Blueprint $table) {
            $table->json('lender_ids')->nullable()->after('funding_sequence_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('funding_reviews', function (Blueprint $table) {
            $table->dropColumn('lender_ids');
        });
    }
};
