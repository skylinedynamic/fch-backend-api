<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            if (!Schema::hasColumn('permissions', 'business_id')) {
                $table->unsignedBigInteger('business_id')->nullable()->after('id');
            }
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropUnique('permissions_name_guard_name_unique');
            $table->unique(['name', 'guard_name', 'business_id'], 'permissions_name_guard_name_business_unique');
        });
    }

    public function down(): void
    {
        // Revert unique index
        DB::statement('ALTER TABLE permissions DROP INDEX permissions_name_guard_name_business_unique');
        DB::statement('ALTER TABLE permissions ADD UNIQUE permissions_name_guard_name_unique (name, guard_name)');

        Schema::table('permissions', function (Blueprint $table) {
            if (Schema::hasColumn('permissions', 'business_id')) {
                $table->dropColumn('business_id');
            }
        });
    }
};
