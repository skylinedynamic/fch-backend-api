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
        Schema::table('funding_sequencings', function (Blueprint $table) {
            $table->json('allowed_documents')->nullable()->after('require_documents_uploaded');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('funding_sequencings', function (Blueprint $table) {
            $table->dropColumn('allowed_documents');
        });
    }
};
