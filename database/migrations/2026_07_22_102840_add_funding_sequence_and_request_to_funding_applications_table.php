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
            $table->unsignedBigInteger('funding_sequence_id')->nullable()->after('partner_id');
            $table->unsignedBigInteger('funding_request_id')->nullable()->after('funding_sequence_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->dropColumn(['funding_sequence_id', 'funding_request_id']);
        });
    }
};
