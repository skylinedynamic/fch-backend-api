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
        Schema::table('app_configurations', function (Blueprint $table) {
            $table->string('module')->nullable()->after('key');
            $table->index(['entity_type', 'entity_id', 'module', 'key'], 'app_configs_composite_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app_configurations', function (Blueprint $table) {
            $table->dropIndex('app_configs_composite_idx');
            $table->dropColumn('module');
        });
    }
};
