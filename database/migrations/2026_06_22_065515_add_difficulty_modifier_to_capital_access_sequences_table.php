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
        Schema::table('capital_access_sequences', function (Blueprint $table) {
            $table->decimal('difficulty_modifier', 15, 2)->nullable()->after('maximum_utilization_threshold');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('capital_access_sequences', function (Blueprint $table) {
            $table->dropColumn('difficulty_modifier');
        });
    }
};
