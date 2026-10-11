<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An edit to a signed application clears `signature_data`. The signature it
     * clears is kept here, so the re-signing step can show the borrower what
     * they signed before alongside the field for the new one.
     */
    public function up(): void
    {
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->json('previous_signature_data')->nullable()->after('signature_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->dropColumn('previous_signature_data');
        });
    }
};
