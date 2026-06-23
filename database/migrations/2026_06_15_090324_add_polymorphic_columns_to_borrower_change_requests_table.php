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
        Schema::table('borrower_change_requests', function (Blueprint $table) {
            $table->string('entity_type')->nullable()->after('db_field_name');
            $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('borrower_change_requests', function (Blueprint $table) {
            $table->dropColumn(['entity_type', 'entity_id']);
        });
    }
};
