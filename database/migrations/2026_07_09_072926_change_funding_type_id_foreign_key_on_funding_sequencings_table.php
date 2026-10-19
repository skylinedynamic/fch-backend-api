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
        try { \DB::statement('ALTER TABLE funding_sequencings DROP FOREIGN KEY funding_sequencings_funding_type_id_foreign'); } catch (\Exception $e) {}
        try { \DB::statement('ALTER TABLE funding_sequencings DROP INDEX funding_sequencings_funding_type_id_foreign'); } catch (\Exception $e) {}

        // The statements above are MySQL syntax, so on SQLite (the test
        // database) they fail silently and the old key to funding_types
        // survived next to the new one - every insert then had to satisfy both.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('funding_sequencings', function (Blueprint $table) {
                $table->dropForeign(['funding_type_id']);
            });
        }

        Schema::table('funding_sequencings', function (Blueprint $table) {
            $table->unsignedBigInteger('funding_type_id')->nullable()->after('funding_group_id')->change();
            $table->foreign('funding_type_id')->references('id')->on('lender_product_categories')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try { \DB::statement('ALTER TABLE funding_sequencings DROP FOREIGN KEY funding_sequencings_funding_type_id_foreign'); } catch (\Exception $e) {}
        
        Schema::table('funding_sequencings', function (Blueprint $table) {
            $table->foreign('funding_type_id')->references('id')->on('funding_types')->nullOnDelete();
        });
    }
};
