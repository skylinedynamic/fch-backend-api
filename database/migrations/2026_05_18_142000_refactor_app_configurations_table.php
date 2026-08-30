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
            // Drop indexes first
            $table->dropIndex(['entity_type', 'entity_id', 'key']);
            
            try {
                $table->dropIndex('app_configs_composite_idx');
            } catch (\Exception $e) {
                // Ignore if index doesn't exist
            }

            // Drop columns
            $table->dropColumn(['entity_type', 'entity_id']);

            // Add partner_id column
            $table->unsignedBigInteger('partner_id')->nullable()->after('id');

            // Add index for fast querying
            $table->index(['partner_id', 'module', 'key'], 'app_configs_partner_module_key_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app_configurations', function (Blueprint $table) {
            $table->dropIndex('app_configs_partner_module_key_idx');
            $table->dropColumn('partner_id');

            $table->string('entity_type')->nullable()->after('id');
            $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
            
            $table->index(['entity_type', 'entity_id', 'key']);
        });
    }
};
