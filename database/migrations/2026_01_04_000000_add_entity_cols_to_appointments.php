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
        Schema::table('appointments', function (Blueprint $table) {
             if (!Schema::hasColumn('appointments', 'entity_type')) {
                $table->string('entity_type')->nullable()->after('id');
            }
            if (!Schema::hasColumn('appointments', 'entity_id')) {
                $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
                $table->index(['entity_type', 'entity_id']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['entity_type', 'entity_id']);
            $table->dropColumn(['entity_type', 'entity_id']);
        });
    }
};
