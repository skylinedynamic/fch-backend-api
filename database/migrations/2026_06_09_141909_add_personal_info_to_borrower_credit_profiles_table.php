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
        Schema::table('borrower_credit_profiles', function (Blueprint $table) {
            $table->json('personal_info')->nullable()->after('document_id');
        });
    }

    
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('borrower_credit_profiles', function (Blueprint $table) {
            $table->dropColumn('personal_info');
        });
    }
};
